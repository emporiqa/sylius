<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
        private ?Security $security = null,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    public function getCsrfToken(): JsonResponse
    {
        if (!$this->csrfTokenManager) {
            return new JsonResponse(['token' => '']);
        }

        $token = $this->csrfTokenManager->getToken('emporiqa_cart')->getValue();
        $response = new JsonResponse(['token' => $token]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function getCart(): JsonResponse
    {
        try {
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => null,
                'cart' => $this->emptyCart(),
            ]);
        }

        /** @var OrderInterface $cart */
        return new JsonResponse([
            'success' => true,
            'checkoutUrl' => $this->buildCheckoutUrl($cart),
            'cart' => $this->formatCart($cart),
        ]);
    }

    public function add(Request $request): JsonResponse
    {
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

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'No active cart session'], Response::HTTP_BAD_REQUEST);
        }

        try {
            foreach ($items as $item) {
                $variantId = $item['variation_id'] ?? null;
                if (!$variantId || !is_numeric($variantId)) {
                    return new JsonResponse(
                        ['success' => false, 'error' => 'Invalid or missing variation_id'],
                        Response::HTTP_BAD_REQUEST,
                    );
                }

                /** @var ProductVariantInterface|null $variant */
                $variant = $this->variantRepository->find((int) $variantId);
                if (!$variant) {
                    return new JsonResponse(
                        ['success' => false, 'error' => 'Variant ' . $variantId . ' not found'],
                        Response::HTTP_NOT_FOUND,
                    );
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                $existingItem = $this->findOrderItemByVariant($cart, (int) $variantId);

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

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart),
                'cart' => $this->formatCart($cart),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart add failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to add item to cart'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request): JsonResponse
    {
        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $variantId = $body['variation_id'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if (!$variantId || !is_numeric($variantId) || $quantity === null) {
            return new JsonResponse(['success' => false, 'error' => 'Missing or invalid variation_id or quantity'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        try {
            $orderItem = $this->findOrderItemByVariant($cart, (int) $variantId);
            if (!$orderItem) {
                return new JsonResponse(['success' => false, 'error' => 'Item not found in cart'], Response::HTTP_NOT_FOUND);
            }

            $newQty = max(1, (int) $quantity);
            $this->orderItemQuantityModifier->modify($orderItem, $newQty);
            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart),
                'cart' => $this->formatCart($cart),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart update failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to update cart'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function remove(Request $request): JsonResponse
    {
        if ($error = $this->validateCsrf($request)) {
            return $error;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $variantId = $body['variation_id'] ?? null;
        if (!$variantId || !is_numeric($variantId)) {
            return new JsonResponse(['success' => false, 'error' => 'Missing or invalid variation_id'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(['success' => false, 'error' => 'Cart is empty'], Response::HTTP_NOT_FOUND);
        }

        try {
            $orderItem = $this->findOrderItemByVariant($cart, (int) $variantId);
            if (!$orderItem) {
                return new JsonResponse(['success' => false, 'error' => 'Item not found in cart'], Response::HTTP_NOT_FOUND);
            }

            $this->orderModifier->removeFromOrder($cart, $orderItem);
            $this->entityManager->persist($cart);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $this->buildCheckoutUrl($cart),
                'cart' => $this->formatCart($cart),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Cart remove failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'error' => 'Failed to remove item'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function clear(Request $request): JsonResponse
    {
        if ($error = $this->validateCsrf($request)) {
            return $error;
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

    public function checkoutUrl(): JsonResponse
    {
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
            'checkoutUrl' => $this->buildCheckoutUrl($cart),
        ]);
    }

    /**
     * Validates CSRF token for authenticated users.
     * Anonymous users skip CSRF (matches Drupal's approach).
     */
    private function validateCsrf(Request $request): ?JsonResponse
    {
        if (!$this->security?->getUser() || !$this->csrfTokenManager) {
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

    private function formatCart(OrderInterface $cart): array
    {
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
                $productUrl = $this->generateProductUrl($product);
            }

            $qty = $orderItem->getQuantity();

            $items[] = [
                'product_id' => $product ? 'product-' . $product->getId() : null,
                'variation_id' => $variant ? 'variation-' . $variant->getId() : null,
                'name' => $orderItem->getProductName() ?? '',
                'quantity' => $qty,
                'unit_price' => round($orderItem->getUnitPrice() / 100, 2),
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
            ];
            $itemCount += $qty;
        }

        return [
            'items' => $items,
            'item_count' => $itemCount,
            'total' => round($cart->getTotal() / 100, 2),
            'currency' => $cart->getCurrencyCode() ?? '',
        ];
    }

    private function buildCheckoutUrl(OrderInterface $cart): ?string
    {
        if ($cart->getItems()->isEmpty()) {
            return null;
        }

        try {
            return $this->router->generate(
                'sylius_shop_checkout_start',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (\Exception) {
            return '/checkout/' . $cart->getId();
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

    private function generateProductUrl(object $product): ?string
    {
        try {
            $translation = $product->getTranslation();
            $slug = method_exists($translation, 'getSlug') ? $translation->getSlug() : null;
            if (!$slug) {
                return null;
            }

            return $this->router->generate(
                'sylius_shop_product_show',
                ['slug' => $slug],
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

        return $baseUrl . '/media/image/' . ltrim($path, '/');
    }

    private function emptyCart(): array
    {
        return ['items' => [], 'item_count' => 0, 'total' => 0.0, 'currency' => ''];
    }
}
