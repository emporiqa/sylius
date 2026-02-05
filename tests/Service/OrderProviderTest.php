<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\OrderProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Doctrine\Common\Collections\ArrayCollection;

class OrderProviderTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private OrderProvider $provider;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->provider = new OrderProvider($this->orderRepository);
    }

    public function testFindOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepository->method('findOneByNumber')->willReturn(null);

        $result = $this->provider->findOrder('000123', null, []);

        $this->assertNull($result);
    }

    public function testFindOrderReturnsFormattedData(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('test@example.com');

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getProductName')->willReturn('Test Product');
        $item->method('getVariantName')->willReturn('Test Variant');
        $item->method('getQuantity')->willReturn(2);
        $item->method('getUnitPrice')->willReturn(1999);

        $shippingMethod = $this->createMock(ShippingMethodInterface::class);
        $shippingMethod->method('getName')->willReturn('DHL Express');

        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getMethod')->willReturn($shippingMethod);
        $shipment->method('getTracking')->willReturn('DHL123456');
        $shipment->method('getState')->willReturn('shipped');

        $completedAt = new \DateTime('2026-01-15T10:30:00+00:00');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000123');
        $order->method('getTotal')->willReturn(5998);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCheckoutCompletedAt')->willReturn($completedAt);
        $order->method('getItems')->willReturn(new ArrayCollection([$item]));
        $order->method('getShipments')->willReturn(new ArrayCollection([$shipment]));
        $order->method('getPaymentState')->willReturn(OrderPaymentStates::STATE_PAID);
        $order->method('getShippingState')->willReturn(OrderShippingStates::STATE_SHIPPED);

        $this->orderRepository->method('findOneByNumber')->willReturn($order);

        $result = $this->provider->findOrder('000123', null, []);

        $this->assertNotNull($result);
        $this->assertSame('000123', $result['order_id']);
        $this->assertSame('shipped', $result['status']);
        $this->assertSame(59.98, $result['total']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Test Product', $result['items'][0]['name']);
        $this->assertSame(2, $result['items'][0]['quantity']);
        $this->assertSame(19.99, $result['items'][0]['price']);
        $this->assertSame('DHL Express', $result['shipping']['method']);
        $this->assertSame('DHL123456', $result['shipping']['tracking_number']);
    }

    public function testFindOrderVerifiesEmail(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('real@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $this->orderRepository->method('findOneByNumber')->willReturn($order);

        $result = $this->provider->findOrder('000123', null, ['email' => 'wrong@example.com']);

        $this->assertNull($result);
    }

    public function testFindOrderWithMatchingEmail(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('test@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000123');
        $order->method('getTotal')->willReturn(1000);
        $order->method('getCurrencyCode')->willReturn('USD');
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCheckoutCompletedAt')->willReturn(new \DateTime());
        $order->method('getItems')->willReturn(new ArrayCollection());
        $order->method('getShipments')->willReturn(new ArrayCollection());
        $order->method('getPaymentState')->willReturn(OrderPaymentStates::STATE_PAID);
        $order->method('getShippingState')->willReturn(OrderShippingStates::STATE_READY);

        $this->orderRepository->method('findOneByNumber')->willReturn($order);

        $result = $this->provider->findOrder('000123', null, ['email' => 'test@example.com']);

        $this->assertNotNull($result);
        $this->assertSame('000123', $result['order_id']);
    }

    public function testResolvesPendingPaymentStatus(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000123');
        $order->method('getTotal')->willReturn(1000);
        $order->method('getCurrencyCode')->willReturn('USD');
        $order->method('getCustomer')->willReturn(null);
        $order->method('getCheckoutCompletedAt')->willReturn(new \DateTime());
        $order->method('getItems')->willReturn(new ArrayCollection());
        $order->method('getShipments')->willReturn(new ArrayCollection());
        $order->method('getPaymentState')->willReturn(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $order->method('getShippingState')->willReturn(OrderShippingStates::STATE_READY);

        $this->orderRepository->method('findOneByNumber')->willReturn($order);

        $result = $this->provider->findOrder('000123', null, []);

        $this->assertSame('pending_payment', $result['status']);
    }
}
