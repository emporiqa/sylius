<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventSubscriber;

use Emporiqa\SyliusPlugin\EventSubscriber\OrderCompleteSubscriber;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;

class OrderCompleteSubscriberTest extends TestCase
{
    private WebhookEventQueue $webhookQueue;
    private RequestStack $requestStack;
    private LoggerInterface $logger;
    private OrderCompleteSubscriber $subscriber;

    protected function setUp(): void
    {
        $webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->webhookQueue = new WebhookEventQueue($webhookSender);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new OrderCompleteSubscriber(
            $this->webhookQueue,
            $this->requestStack,
            $this->logger,
        );
    }

    public function testSubscribedEvents(): void
    {
        $events = OrderCompleteSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('workflow.sylius_order_checkout.completed.complete', $events);
        $this->assertSame(['onOrderComplete', 50], $events['workflow.sylius_order_checkout.completed.complete']);
    }

    public function testQueuesOrderCompletedWebhook(): void
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(456);

        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getVariant')->willReturn($variant);
        $orderItem->method('getQuantity')->willReturn(2);
        $orderItem->method('getUnitPrice')->willReturn(2999);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000123');
        $order->method('getId')->willReturn(123);
        $order->method('getTotal')->willReturn(5998);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$orderItem]));

        $request = Request::create('/checkout/complete');
        $request->cookies->set('emporiqa_sid', 'sess-abc123');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $event = new CompletedEvent($order, new Marking());
        $this->subscriber->onOrderComplete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testIgnoresNonOrderSubjects(): void
    {
        $event = new CompletedEvent(new \stdClass(), new Marking());
        $this->subscriber->onOrderComplete($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testHandlesEmptySessionCookie(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000456');
        $order->method('getId')->willReturn(456);
        $order->method('getTotal')->willReturn(0);
        $order->method('getCurrencyCode')->willReturn('USD');
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $request = Request::create('/checkout/complete');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $event = new CompletedEvent($order, new Marking());
        $this->subscriber->onOrderComplete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testRejectsInvalidSessionCookieCharacters(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000789');
        $order->method('getId')->willReturn(789);
        $order->method('getTotal')->willReturn(0);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $request = Request::create('/checkout/complete');
        $request->cookies->set('emporiqa_sid', '<script>alert(1)</script>');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $event = new CompletedEvent($order, new Marking());
        $this->subscriber->onOrderComplete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testLogsErrorOnException(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willThrowException(new \RuntimeException('DB error'));

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to send order.completed webhook'));

        $event = new CompletedEvent($order, new Marking());
        $this->subscriber->onOrderComplete($event);
    }

    public function testUsesOrderIdWhenNumberIsNull(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn(null);
        $order->method('getId')->willReturn(777);
        $order->method('getTotal')->willReturn(0);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = new CompletedEvent($order, new Marking());
        $this->subscriber->onOrderComplete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }
}
