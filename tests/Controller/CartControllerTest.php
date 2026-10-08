<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Controller\CartController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CartControllerTest extends TestCase
{
    private CartContextInterface $cartContext;
    private OrderModifierInterface $orderModifier;
    private OrderItemQuantityModifierInterface $orderItemQuantityModifier;
    private FactoryInterface $orderItemFactory;
    private ProductVariantRepositoryInterface $variantRepository;
    private EntityManagerInterface $entityManager;
    private RouterInterface $router;
    private CsrfTokenManagerInterface $csrfTokenManager;
    private CartController $controller;
    private ChannelInterface $channel;

    protected function setUp(): void
    {
        $this->cartContext = $this->createMock(CartContextInterface::class);
        $this->orderModifier = $this->createMock(OrderModifierInterface::class);
        $this->orderItemQuantityModifier = $this->createMock(OrderItemQuantityModifierInterface::class);
        $this->orderItemFactory = $this->createMock(FactoryInterface::class);
        $this->variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->channel = $this->createMock(ChannelInterface::class);

        $requestContext = new RequestContext();
        $requestContext->setHost('shop.example.com');
        $requestContext->setScheme('https');
        $this->router->method('getContext')->willReturn($requestContext);

        // Permissive CSRF manager: business-logic tests don't exercise the CSRF
        // path. Tests that need stricter CSRF behavior build their own controller.
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);
        $this->csrfTokenManager->method('getToken')->willReturn(new CsrfToken('emporiqa_cart', 'test-token'));

        $this->controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $this->csrfTokenManager,
        );
    }

    private function createMockCart(array $items = [], int $total = 0, string $currency = 'EUR'): OrderInterface
    {
        $cart = $this->createMock(OrderInterface::class);
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);
        $cart->method('getItems')->willReturn($collection);
        $cart->method('getTotal')->willReturn($total);
        $cart->method('getCurrencyCode')->willReturn($currency);
        $cart->method('getId')->willReturn(42);
        $cart->method('getChannel')->willReturn($this->channel);

        return $cart;
    }

    /** A variant Sylius's own add-to-cart would accept: enabled, product enabled and in the cart's channel. */
    private function sellableVariant(int $id, bool $enabled = true, bool $productEnabled = true, bool $inChannel = true): ProductVariantInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn($productEnabled);
        $product->method('hasChannel')->willReturnCallback(fn ($channel): bool => $inChannel && $channel === $this->channel);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getCode')->willReturn('VARIANT_' . $id);
        $variant->method('isEnabled')->willReturn($enabled);
        $variant->method('getProduct')->willReturn($product);

        return $variant;
    }

    private function controllerWithStock(AvailabilityCheckerInterface $checker, int $limit = 9999): CartController
    {
        return new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $this->csrfTokenManager,
            null,
            true,
            '/media/image/',
            $checker,
            $limit,
        );
    }

    private function createMockOrderItem(int $variantId, int $quantity = 1, int $unitPrice = 1999): OrderItemInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn($variantId);
        $variant->method('getImages')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);
        $product->method('getImages')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $variant->method('getProduct')->willReturn($product);

        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getVariant')->willReturn($variant);
        $orderItem->method('getQuantity')->willReturn($quantity);
        $orderItem->method('getUnitPrice')->willReturn($unitPrice);
        $orderItem->method('getProductName')->willReturn('Test Product');

        return $orderItem;
    }

    public function testGetCartReturnsEmptyWhenNoCart(): void
    {
        $this->cartContext->method('getCart')->willThrowException(new CartNotFoundException());

        $response = $this->controller->getCart(new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['checkoutUrl']);
        $this->assertSame(0, $data['cart']['item_count']);
        $this->assertEmpty($data['cart']['items']);
    }

    public function testGetCartReturnsCartData(): void
    {
        $orderItem = $this->createMockOrderItem(456, 2, 2999);
        $cart = $this->createMockCart([$orderItem], 5998, 'EUR');

        $this->cartContext->method('getCart')->willReturn($cart);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $response = $this->controller->getCart(new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['cart']['items']);
        $this->assertSame('variation-456', $data['cart']['items'][0]['variation_id']);
        $this->assertSame(2, $data['cart']['items'][0]['quantity']);
        $this->assertSame(29.99, $data['cart']['items'][0]['unit_price']);
        $this->assertSame(59.98, $data['cart']['total']);
        $this->assertSame('EUR', $data['cart']['currency']);
    }

    public function testAddRejectsInvalidJson(): void
    {
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], 'not json');

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testAddRejectsEmptyItems(): void
    {
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], json_encode(['items' => []]));

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('No items provided', $data['error']);
    }

    public function testAddReturnsNotFoundForInvalidVariant(): void
    {
        $cart = $this->createMockCart();
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->variantRepository->method('find')->willReturn(null);

        $body = json_encode(['items' => [['variation_id' => 999, 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('999', $data['error']);
    }

    public function testAddCreatesNewOrderItem(): void
    {
        $cart = $this->createMockCart([], 1999, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $newOrderItem = $this->createMock(OrderItemInterface::class);
        $this->orderItemFactory->method('createNew')->willReturn($newOrderItem);

        $this->orderItemQuantityModifier
            ->expects($this->once())
            ->method('modify')
            ->with($newOrderItem, 2);

        $this->orderModifier
            ->expects($this->once())
            ->method('addToOrder')
            ->with($cart, $newOrderItem);

        $this->entityManager->expects($this->once())->method('flush');

        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 2]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testUpdateRejectsMissingVariationId(): void
    {
        $body = json_encode(['quantity' => 3]);
        $request = Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body);

        $response = $this->controller->update($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testUpdateModifiesExistingItem(): void
    {
        $orderItem = $this->createMockOrderItem(456, 1, 1999);
        $cart = $this->createMockCart([$orderItem], 5997, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $this->orderItemQuantityModifier
            ->expects($this->once())
            ->method('modify')
            ->with($orderItem, 3);

        $this->entityManager->expects($this->once())->method('flush');

        $body = json_encode(['variation_id' => 456, 'quantity' => 3]);
        $request = Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body);

        $response = $this->controller->update($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testUpdateReturnsNotFoundWhenCartEmpty(): void
    {
        $this->cartContext->method('getCart')->willThrowException(new CartNotFoundException());

        $body = json_encode(['variation_id' => 456, 'quantity' => 3]);
        $request = Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body);

        $response = $this->controller->update($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRemoveDeletesItemFromCart(): void
    {
        $orderItem = $this->createMockOrderItem(456);
        $cart = $this->createMockCart([$orderItem], 0, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $this->orderModifier
            ->expects($this->once())
            ->method('removeFromOrder')
            ->with($cart, $orderItem);

        $this->entityManager->expects($this->once())->method('flush');

        $body = json_encode(['variation_id' => 456]);
        $request = Request::create('/emporiqa/api/cart/remove', 'POST', [], [], [], [], $body);

        $response = $this->controller->remove($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRemoveReturnsNotFoundForMissingItem(): void
    {
        $cart = $this->createMockCart();
        $this->cartContext->method('getCart')->willReturn($cart);

        $body = json_encode(['variation_id' => 999]);
        $request = Request::create('/emporiqa/api/cart/remove', 'POST', [], [], [], [], $body);

        $response = $this->controller->remove($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Item not found in cart', $data['error']);
    }

    public function testClearRemovesAllItems(): void
    {
        $orderItem1 = $this->createMockOrderItem(1);
        $orderItem2 = $this->createMockOrderItem(2);
        $cart = $this->createMockCart([$orderItem1, $orderItem2]);
        $this->cartContext->method('getCart')->willReturn($cart);

        $this->orderModifier->expects($this->exactly(2))->method('removeFromOrder');
        $this->entityManager->expects($this->once())->method('flush');

        $request = Request::create('/emporiqa/api/cart/clear', 'POST');
        $response = $this->controller->clear($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['checkoutUrl']);
        $this->assertSame(0, $data['cart']['item_count']);
    }

    public function testClearReturnsEmptyWhenNoCart(): void
    {
        $this->cartContext->method('getCart')->willThrowException(new CartNotFoundException());

        $request = Request::create('/emporiqa/api/cart/clear', 'POST');
        $response = $this->controller->clear($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testCheckoutUrlReturnsUrl(): void
    {
        $orderItem = $this->createMockOrderItem(456);
        $cart = $this->createMockCart([$orderItem], 1999, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $response = $this->controller->checkoutUrl(new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('https://shop.example.com/checkout', $data['checkoutUrl']);
    }

    public function testCheckoutUrlReturnsNotFoundWhenCartEmpty(): void
    {
        $cart = $this->createMockCart();
        $this->cartContext->method('getCart')->willReturn($cart);

        $response = $this->controller->checkoutUrl(new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Cart is empty', $data['error']);
    }

    public function testCheckoutUrlReturnsNotFoundWhenNoCart(): void
    {
        $this->cartContext->method('getCart')->willThrowException(new CartNotFoundException());

        $response = $this->controller->checkoutUrl(new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGetCsrfTokenReturnsEmptyWhenNoCsrfManager(): void
    {
        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
        );

        $response = $controller->getCsrfToken();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('', $data['token']);
    }

    public function testGetCsrfTokenHasNoStoreCacheHeader(): void
    {
        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('getToken')->willReturn(new CsrfToken('emporiqa_cart', 'test-token'));

        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $csrfManager,
        );

        $response = $controller->getCsrfToken();

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $data = json_decode($response->getContent(), true);
        $this->assertSame('test-token', $data['token']);
    }

    public function testCsrfValidationRejectsInvalidToken(): void
    {
        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(false);

        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $csrfManager,
        );

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $controller->add($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid CSRF token', $data['error']);
    }

    public function testCsrfValidationEnforcedForAnonymousUser(): void
    {
        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(false);

        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $csrfManager,
        );

        $request = Request::create('/emporiqa/api/cart/clear', 'POST');
        $response = $controller->clear($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid CSRF token', $data['error']);
    }

    public function testAddReturnsNotFoundForUnknownStringVariationId(): void
    {
        $cart = $this->createMockCart();
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->variantRepository->method('findOneBy')->willReturn(null);

        $body = json_encode(['items' => [['variation_id' => 'UNKNOWN_SKU', 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddRejectsMissingVariationId(): void
    {
        $cart = $this->createMockCart();
        $this->cartContext->method('getCart')->willReturn($cart);

        $body = json_encode(['items' => [['quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing variation_id', $data['error']);
    }

    public function testAddResolvesVariationIdFormat(): void
    {
        $cart = $this->createMockCart([], 1999, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $newOrderItem = $this->createMock(OrderItemInterface::class);
        $this->orderItemFactory->method('createNew')->willReturn($newOrderItem);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $body = json_encode(['items' => [['variation_id' => 'variation-456', 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAddResolvesProductIdFormat(): void
    {
        $cart = $this->createMockCart([], 1999, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(789);
        $this->variantRepository->method('findOneBy')->with(['product' => 10, 'enabled' => true], ['position' => 'ASC'])->willReturn($variant);

        $newOrderItem = $this->createMock(OrderItemInterface::class);
        $this->orderItemFactory->method('createNew')->willReturn($newOrderItem);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $body = json_encode(['items' => [['variation_id' => 'product-10', 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAddResolvesSKUFormat(): void
    {
        $cart = $this->createMockCart([], 1999, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(123);
        $this->variantRepository->method('findOneBy')->with(['code' => 'PHONE_RED'])->willReturn($variant);

        $newOrderItem = $this->createMock(OrderItemInterface::class);
        $this->orderItemFactory->method('createNew')->willReturn($newOrderItem);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $body = json_encode(['items' => [['variation_id' => 'PHONE_RED', 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAddIncrementsExistingItemQuantity(): void
    {
        $orderItem = $this->createMockOrderItem(456, 2, 1999);
        $cart = $this->createMockCart([$orderItem], 5997, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $this->orderItemQuantityModifier
            ->expects($this->once())
            ->method('modify')
            ->with($orderItem, 5); // 2 existing + 3 new

        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 3]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAddHandlesFlushException(): void
    {
        $cart = $this->createMockCart([], 0, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);

        $newOrderItem = $this->createMock(OrderItemInterface::class);
        $this->orderItemFactory->method('createNew')->willReturn($newOrderItem);

        $this->entityManager->method('flush')->willThrowException(
            new \Exception('Product variant has no price for channel')
        );

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $this->controller->add($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testCartOperationEventCanCancelAdd(): void
    {
        $dispatcher = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof \Emporiqa\SyliusPlugin\Event\CartOperationEvent) {
                    $event->cancelOperation('Not allowed');
                }
                return $event;
            }
        );

        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $this->csrfTokenManager,
            $dispatcher,
        );

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $controller->add($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Not allowed', $data['error']);
    }

    public function testGetCartWithJPYCurrency(): void
    {
        // Sylius stores JPY in hundredths too: 199900 is 1,999 JPY.
        $orderItem = $this->createMockOrderItem(456, 1, 199900);
        $cart = $this->createMockCart([$orderItem], 199900, 'JPY');
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');

        $response = $this->controller->getCart(new Request());
        $data = json_decode($response->getContent(), true);

        // JSON roundtrip converts 1999.0 to int 1999
        $this->assertEquals(1999, $data['cart']['total']);
        $this->assertEquals(1999, $data['cart']['items'][0]['unit_price']);
        $this->assertSame('JPY', $data['cart']['currency']);
    }

    public function testRemoveRejectsMissingVariationId(): void
    {
        $body = json_encode(['some_field' => 'value']);
        $request = Request::create('/emporiqa/api/cart/remove', 'POST', [], [], [], [], $body);

        $response = $this->controller->remove($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing variation_id', $data['error']);
    }

    public function testUpdateReturnsNotFoundWhenItemNotInCart(): void
    {
        $cart = $this->createMockCart([], 0, 'EUR');
        $this->cartContext->method('getCart')->willReturn($cart);

        $variant = $this->sellableVariant(999);
        $this->variantRepository->method('find')->with(999)->willReturn($variant);

        $body = json_encode(['variation_id' => 999, 'quantity' => 3]);
        $request = Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body);

        $response = $this->controller->update($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Item not found in cart', $data['error']);
    }

    public static function unsellableVariants(): array
    {
        return [
            'disabled variant' => [false, true, true],
            'disabled product' => [true, false, true],
            'product not in the cart channel' => [true, true, false],
        ];
    }

    #[DataProvider('unsellableVariants')]
    public function testAddRefusesAVariantTheShopWouldNotSell(bool $enabled, bool $productEnabled, bool $inChannel): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart());
        $this->variantRepository->method('find')->with(456)->willReturn($this->sellableVariant(456, $enabled, $productEnabled, $inChannel));
        $this->orderModifier->expects($this->never())->method('addToOrder');
        $this->entityManager->expects($this->never())->method('flush');

        $body = json_encode(['items' => [['variation_id' => 'variation-456', 'quantity' => 1]]]);
        $response = $this->controller->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('Variant variation-456 not found', json_decode($response->getContent(), true)['error']);
    }

    #[DataProvider('unsellableVariants')]
    public function testUpdateRefusesAVariantTheShopWouldNotSell(bool $enabled, bool $productEnabled, bool $inChannel): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$this->createMockOrderItem(456, 1)]));
        $this->variantRepository->method('find')->with(456)->willReturn($this->sellableVariant(456, $enabled, $productEnabled, $inChannel));
        $this->orderItemQuantityModifier->expects($this->never())->method('modify');

        $body = json_encode(['variation_id' => 456, 'quantity' => 2]);
        $response = $this->controller->update(Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddRefusesACartWithoutAChannel(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $cart->method('getChannel')->willReturn(null);
        $this->cartContext->method('getCart')->willReturn($cart);
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456));

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $response = $this->controller->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddChecksStockForTheCartQuantityPlusTheAddedOne(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$this->createMockOrderItem(456, 2)]));
        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->with(456)->willReturn($variant);
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->expects($this->once())->method('isStockSufficient')->with($variant, 5)->willReturn(false);
        $this->orderItemQuantityModifier->expects($this->never())->method('modify');
        $this->entityManager->expects($this->never())->method('flush');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 3]]]);
        $response = $this->controllerWithStock($checker)->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('Not enough stock for VARIANT_456', json_decode($response->getContent(), true)['error']);
    }

    public function testAddWithEnoughStockAddsTheItem(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart());
        $this->variantRepository->method('find')->with(456)->willReturn($this->sellableVariant(456));
        $this->orderItemFactory->method('createNew')->willReturn($this->createMock(OrderItemInterface::class));
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->method('isStockSufficient')->willReturn(true);
        $this->orderModifier->expects($this->once())->method('addToOrder');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 2]]]);
        $response = $this->controllerWithStock($checker)->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAddRefusedForOneItemChangesNothingInTheCart(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart());
        $variants = [456 => $this->sellableVariant(456), 789 => $this->sellableVariant(789, false)];
        $this->variantRepository->method('find')->willReturnCallback(fn ($id) => $variants[$id] ?? null);
        $this->orderItemFactory->method('createNew')->willReturn($this->createMock(OrderItemInterface::class));
        $this->orderModifier->expects($this->never())->method('addToOrder');
        $this->orderItemQuantityModifier->expects($this->never())->method('modify');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1], ['variation_id' => 789, 'quantity' => 1]]]);
        $response = $this->controller->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAddRefusesAQuantityAboveTheSyliusLimit(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart());
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456));
        $this->orderModifier->expects($this->never())->method('addToOrder');

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 99999]]]);
        $response = $this->controller->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('Quantity must be between 1 and 9999', json_decode($response->getContent(), true)['error']);
    }

    public function testUpdateRefusesAQuantityAboveTheConfiguredLimit(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$this->createMockOrderItem(456, 1)]));
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456));
        $this->orderItemQuantityModifier->expects($this->never())->method('modify');
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->method('isStockSufficient')->willReturn(true);

        $body = json_encode(['variation_id' => 456, 'quantity' => 51]);
        $response = $this->controllerWithStock($checker, 50)->update(Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testUpdateRaisingAboveStockIsRefused(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$this->createMockOrderItem(456, 1)]));
        $variant = $this->sellableVariant(456);
        $this->variantRepository->method('find')->willReturn($variant);
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->expects($this->once())->method('isStockSufficient')->with($variant, 4)->willReturn(false);
        $this->orderItemQuantityModifier->expects($this->never())->method('modify');

        $body = json_encode(['variation_id' => 456, 'quantity' => 4]);
        $response = $this->controllerWithStock($checker)->update(Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testUpdateLoweringIsAllowedWhateverTheStock(): void
    {
        $orderItem = $this->createMockOrderItem(456, 5);
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$orderItem]));
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456));
        $this->router->method('generate')->willReturn('https://shop.example.com/checkout');
        $checker = $this->createMock(AvailabilityCheckerInterface::class);
        $checker->expects($this->never())->method('isStockSufficient');
        $this->orderItemQuantityModifier->expects($this->once())->method('modify')->with($orderItem, 2);

        $body = json_encode(['variation_id' => 456, 'quantity' => 2]);
        $response = $this->controllerWithStock($checker)->update(Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRemoveStillWorksForADisabledVariant(): void
    {
        $orderItem = $this->createMockOrderItem(456, 1);
        $this->cartContext->method('getCart')->willReturn($this->createMockCart([$orderItem]));
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456, false));
        $this->orderModifier->expects($this->once())->method('removeFromOrder');

        $body = json_encode(['variation_id' => 456]);
        $response = $this->controller->remove(Request::create('/emporiqa/api/cart/remove', 'POST', [], [], [], [], $body));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAFailedAddLogsTheExceptionClassNotItsMessage(): void
    {
        $this->cartContext->method('getCart')->willReturn($this->createMockCart());
        $this->variantRepository->method('find')->willReturn($this->sellableVariant(456));
        $this->orderItemFactory->method('createNew')->willReturn($this->createMock(OrderItemInterface::class));
        $this->entityManager->method('flush')->willThrowException(new \RuntimeException('SQLSTATE[23000] with params [42, "secret"]'));
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            $logger,
            $this->csrfTokenManager,
        );

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $controller->add(Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body));

        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertSame(\RuntimeException::class, $context['exception_class']);
        $this->assertStringNotContainsString('SQLSTATE', $message . json_encode($context));
    }
}
