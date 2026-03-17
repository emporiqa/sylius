<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Event\CartOperationEvent;
use Emporiqa\SyliusPlugin\Service\CurrencyHelper;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CartController
{
    public function __construct(
        private CartContextInterface $cartContext,
        private OrderModifierInterface $orderModifier,
        private OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private FactoryInterface $orderItemFactory,
        private ProductVariantRepositoryInterface $variantRepository,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private ?LoggerInterface $logger = null,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private bool $enabled = true,
        private string $mediaBasePath = '/media/image/',
    ) {}

    private function guardEnabled(): ?JsonResponse
    {
        if (!$this->enabled) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    public function getCsrfToken(): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        if (!$this->csrfTokenManager) {
            return new JsonResponse(['token' => '']);
        }

        $token = $this->csrfTokenManager->getToken('emporiqa_cart')->getValue();
        $response = new JsonResponse(['token' => $token]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function getCart(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        try {
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => null,
                'cart' => $this->emptyCart(),
            ]);
        }

        $locale = $this->getRequestLocale($request);

        /** @var OrderInterface $cart */
        return new JsonResponse([
            'success' => true,
            'checkoutUrl' => $this->buildCheckoutUrl($cart, $locale),
            'cart' => $this->formatCart($cart, $locale),
        ]);
    }

    public function add(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $items = $body['items'] ?? [];
        if (empty($items)) {
            return new JsonResponse(['success' => false, 'error' => 'No items provided'], Response::HTTP_BAD_REQUEST);
        }

        if ($cancelResponse = $this->dispatchCartOperation('add', $body)) {
            return $cancelResponse;
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'No active cart session'], Response::HTTP_BAD_REQUEST);
        }

        try {
            foreach ($items as $item) {
                $rawId = $item['variation_id'] ?? null;
                if (!$rawId) {
                    return new JsonResponse(
                        ['success' => false, 'error' => 'Missing variation_id'],
                        Response::HTTP_BAD_REQUEST,
                    );
                }

                $variant = $this->resolveVariant($rawId);
                if (!$variant) {
                    return new JsonResponse(
                        ['success' => false, 'error' => 'Variant ' . $rawId . ' not found'],
                        Response::HTTP_NOT_FOUND,
                    );
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                $existingItem = $this->findOrderItemByVariant($cart, (int) $variant->getId());

                if ($existingItem) {
                    $newQty = $existingItem->getQuantity() + $quantity;
                    $this->orderItemQuantityModifier->modify($existingItem, $newQty);
                } else {
                    /** @var OrderItemInterface $orderItem */
                    $orderItem = $this->orderItemFactory->createNew();
                    $orderItem->setVariant($variant);
                    $this->orderItemQuantityModifier->modify($orderItem, $quantity);
                    $this->orderModifier->addToOrder($cart, $orderItem);
                }
            }

            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            $locale = $this->getRequestLocale($request);

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart, $locale),
                'cart' => $this->formatCart($cart, $locale),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart add failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to add item to cart'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $rawId = $body['variation_id'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if (!$rawId || $quantity === null) {
            return new JsonResponse(['success' => false, 'error' => 'Missing variation_id or quantity'], Response::HTTP_BAD_REQUEST);
        }

        if ($cancelResponse = $this->dispatchCartOperation('update', $body)) {
            return $cancelResponse;
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        try {
            $variant = $this->resolveVariant($rawId);
            $orderItem = $variant ? $this->findOrderItemByVariant($cart, (int) $variant->getId()) : null;
            if (!$orderItem) {
                return new JsonResponse(['success' => false, 'error' => 'Item not found in cart'], Response::HTTP_NOT_FOUND);
            }

            $newQty = max(1, (int) $quantity);
            $this->orderItemQuantityModifier->modify($orderItem, $newQty);
            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            $locale = $this->getRequestLocale($request);

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart, $locale),
                'cart' => $this->formatCart($cart, $locale),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart update failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to update cart'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function remove(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $rawId = $body['variation_id'] ?? null;
        if (!$rawId) {
            return new JsonResponse(['success' => false, 'error' => 'Missing variation_id'], Response::HTTP_BAD_REQUEST);
        }

        if ($cancelResponse = $this->dispatchCartOperation('remove', $body)) {
            return $cancelResponse;
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        try {
            $variant = $this->resolveVariant($rawId);
            $orderItem = $variant ? $this->findOrderItemByVariant($cart, (int) $variant->getId()) : null;
            if (!$orderItem) {
                return new JsonResponse(['success' => false, 'error' => 'Item not found in cart'], Response::HTTP_NOT_FOUND);
            }

            $this->orderModifier->removeFromOrder($cart, $orderItem);
            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            $locale = $this->getRequestLocale($request);

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart, $locale),
                'cart' => $this->formatCart($cart, $locale),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart remove failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to remove item'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function clear(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        if ($cancelResponse = $this->dispatchCartOperation('clear', [])) {
            return $cancelResponse;
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => null,
                'cart' => $this->emptyCart(),
            ]);
        }

        try {
            foreach ($cart->getItems()->toArray() as $item) {
                $this->orderModifier->removeFromOrder($cart, $item);
            }
            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => null,
                'cart' => $this->emptyCart(),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart clear failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to clear cart'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function checkoutUrl(Request $request): JsonResponse
    {
        if ($notFound = $this->guardEnabled()) {
            return $notFound;
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        if ($cart->getItems()->isEmpty()) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'checkoutUrl' => $this->buildCheckoutUrl($cart, $this->getRequestLocale($request)),
        ]);
    }

    private function validateCsrf(Request $request): ?JsonResponse
    {
        if (!$this->csrfTokenManager) {
            return null;
        }

        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('emporiqa_cart', $token))) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid CSRF token'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    /**
     * Resolves a variant from various identification_number formats:
     * - "variation-{id}" → variant by Sylius ID
     * - "product-{id}" → first variant of that product (simple products)
     * - Numeric (e.g. 24) → variant by Sylius ID
     * - Any other string (e.g. SKU "PHONE_RED") → variant by code
     */
    private function resolveVariant(string|int $rawId): ?ProductVariantInterface
    {
        $rawId = (string) $rawId;

        if (preg_match('/^variation-(\d+)$/', $rawId, $matches)) {
            return $this->variantRepository->find((int) $matches[1]);
        }

        if (preg_match('/^product-(\d+)$/', $rawId, $matches)) {
            return $this->variantRepository->findOneBy(['product' => (int) $matches[1]]);
        }

        if (is_numeric($rawId)) {
            return $this->variantRepository->find((int) $rawId);
        }

        return $this->variantRepository->findOneBy(['code' => $rawId]);
    }

    private function findOrderItemByVariant(OrderInterface $cart, int $variantId): ?OrderItemInterface
    {
        foreach ($cart->getItems() as $orderItem) {
            /** @var OrderItemInterface $orderItem */
            $variant = $orderItem->getVariant();
            if ($variant && $variant->getId() === $variantId) {
                return $orderItem;
            }
        }

        return null;
    }

    private function formatCart(OrderInterface $cart, ?string $locale = null): array
    {
        $currencyCode = $cart->getCurrencyCode() ?? '';
        $items = [];
        $itemCount = 0;

        foreach ($cart->getItems() as $orderItem) {
            /** @var OrderItemInterface $orderItem */
            $variant = $orderItem->getVariant();
            $product = $variant?->getProduct();

            $imageUrl = null;
            $productUrl = null;

            if ($variant) {
                $imageUrl = $this->resolveImageUrl($variant);
            }

            if ($product) {
                $productUrl = $this->generateProductUrl($product, $locale);
            }

            $qty = $orderItem->getQuantity();

            $items[] = [
                'product_id' => $product ? 'product-' . $product->getId() : null,
                'variation_id' => $variant ? 'variation-' . $variant->getId() : null,
                'name' => $orderItem->getProductName() ?? '',
                'quantity' => $qty,
                'unit_price' => CurrencyHelper::toCurrencyUnits($orderItem->getUnitPrice(), $currencyCode),
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
            ];
            $itemCount += $qty;
        }

        return [
            'items' => $items,
            'item_count' => $itemCount,
            'total' => CurrencyHelper::toCurrencyUnits($cart->getTotal(), $currencyCode),
            'currency' => $currencyCode,
        ];
    }

    private function buildCheckoutUrl(OrderInterface $cart, ?string $locale = null): ?string
    {
        if ($cart->getItems()->isEmpty()) {
            return null;
        }

        try {
            $params = [];
            if ($locale) {
                $params['_locale'] = $locale;
            }

            return $this->router->generate(
                'sylius_shop_checkout_start',
                $params,
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to generate checkout URL', [
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveImageUrl(ProductVariantInterface $variant): ?string
    {
        foreach ($variant->getImages() as $image) {
            if ($image->getPath()) {
                return $this->generateImageUrl($image->getPath());
            }
        }

        $product = $variant->getProduct();
        if ($product) {
            foreach ($product->getImages() as $image) {
                if ($image->getPath()) {
                    return $this->generateImageUrl($image->getPath());
                }
            }
        }

        return null;
    }

    private function generateProductUrl(object $product, ?string $locale = null): ?string
    {
        try {
            $translation = $locale && method_exists($product, 'getTranslation')
                ? $product->getTranslation($locale)
                : $product->getTranslation();
            $slug = method_exists($translation, 'getSlug') ? $translation->getSlug() : null;
            if (!$slug) {
                return null;
            }

            $params = ['slug' => $slug];
            if ($locale) {
                $params['_locale'] = $locale;
            }

            return $this->router->generate(
                'sylius_shop_product_show',
                $params,
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function generateImageUrl(string $path): string
    {
        $context = $this->router->getContext();
        $scheme = $context->getScheme() ?: 'https';
        $host = $context->getHost();
        $baseUrl = $host ? $scheme . '://' . $host : '';

        $mediaBase = rtrim($this->mediaBasePath, '/');

        return $baseUrl . $mediaBase . '/' . ltrim($path, '/');
    }

    private function dispatchCartOperation(string $operation, array $parameters): ?JsonResponse
    {
        if (!$this->eventDispatcher) {
            return null;
        }

        $event = new CartOperationEvent($operation, $parameters);
        $this->eventDispatcher->dispatch($event, CartOperationEvent::NAME);

        if ($event->isCancelled()) {
            $reason = $event->getCancelReason() ?: 'Operation not allowed';
            return new JsonResponse(
                ['success' => false, 'error' => $reason],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    private function getRequestLocale(Request $request): ?string
    {
        $locale = $request->headers->get('X-Locale', '');

        return $locale !== '' ? $locale : null;
    }

    private function emptyCart(): array
    {
        return ['items' => [], 'item_count' => 0, 'total' => 0.0, 'currency' => ''];
    }
}
