<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Controller\CartController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
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
    private CartController $controller;

    protected function setUp(): void
    {
        $this->cartContext = $this->createMock(CartContextInterface::class);
        $this->orderModifier = $this->createMock(OrderModifierInterface::class);
        $this->orderItemQuantityModifier = $this->createMock(OrderItemQuantityModifierInterface::class);
        $this->orderItemFactory = $this->createMock(FactoryInterface::class);
        $this->variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->router = $this->createMock(RouterInterface::class);

        $requestContext = new RequestContext();
        $requestContext->setHost('shop.example.com');
        $requestContext->setScheme('https');
        $this->router->method('getContext')->willReturn($requestContext);

        $this->controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
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

        return $cart;
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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
        $response = $this->controller->getCsrfToken();

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

    public function testCsrfValidationRejectsInvalidTokenForAuthenticatedUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

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
            $security,
            $csrfManager,
        );

        $body = json_encode(['items' => [['variation_id' => 456, 'quantity' => 1]]]);
        $request = Request::create('/emporiqa/api/cart/add', 'POST', [], [], [], [], $body);

        $response = $controller->add($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid CSRF token', $data['error']);
    }

    public function testCsrfValidationSkippedForAnonymousUser(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->expects($this->never())->method('isTokenValid');

        $controller = new CartController(
            $this->cartContext,
            $this->orderModifier,
            $this->orderItemQuantityModifier,
            $this->orderItemFactory,
            $this->variantRepository,
            $this->entityManager,
            $this->router,
            null,
            $security,
            $csrfManager,
        );

        $this->cartContext->method('getCart')->willThrowException(new CartNotFoundException());

        $request = Request::create('/emporiqa/api/cart/clear', 'POST');
        $response = $controller->clear($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(789);
        $this->variantRepository->method('findOneBy')->with(['product' => 10])->willReturn($variant);

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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(123);
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);
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
            null,
            null,
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
        $orderItem = $this->createMockOrderItem(456, 1, 1999);
        $cart = $this->createMockCart([$orderItem], 1999, 'JPY');
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

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(999);
        $this->variantRepository->method('find')->with(999)->willReturn($variant);

        $body = json_encode(['variation_id' => 999, 'quantity' => 3]);
        $request = Request::create('/emporiqa/api/cart/update', 'POST', [], [], [], [], $body);

        $response = $this->controller->update($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Item not found in cart', $data['error']);
    }
}
