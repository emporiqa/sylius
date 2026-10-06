<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventSubscriber;

use Emporiqa\SyliusPlugin\EventSubscriber\OrderCompleteSubscriber;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    /**
     * Winzou's TransitionEvent shape: one generic event per transition, the
     * order on the state machine, not a subject.
     */
    private static function winzouEvent(object $order, string $graph, string $transition): object
    {
        $stateMachine = new class($order, $graph) {
            public function __construct(private object $object, private string $graph) {}

            public function getObject(): object
            {
                return $this->object;
            }

            public function getGraph(): string
            {
                return $this->graph;
            }
        };

        return new class($stateMachine, $transition) {
            public function __construct(private object $stateMachine, private string $transition) {}

            public function getStateMachine(): object
            {
                return $this->stateMachine;
            }

            public function getTransition(): string
            {
                return $this->transition;
            }
        };
    }

    private function simpleOrder(string $number = '000000009'): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn($number);
        $order->method('getTotal')->willReturn(1000);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        return $order;
    }

    public function testSubscribesToWinzouGenericPostTransition(): void
    {
        // Winzou never dispatches graph-specific event names.
        $events = OrderCompleteSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('winzou.state_machine.post_transition', $events);
        $this->assertArrayNotHasKey('winzou.state_machine.sylius_order_checkout.post_transition.complete', $events);
        $this->assertArrayHasKey('workflow.sylius_order_payment.completed.pay', $events);
    }

    public function testWinzouCheckoutCompleteQueuesTheOrder(): void
    {
        $this->subscriber->onWinzouTransition(self::winzouEvent($this->simpleOrder(), 'sylius_order_checkout', 'complete'));

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testWinzouOtherTransitionsAreIgnored(): void
    {
        $this->subscriber->onWinzouTransition(self::winzouEvent($this->simpleOrder(), 'sylius_order_checkout', 'select_shipping'));
        $this->subscriber->onWinzouTransition(self::winzouEvent($this->simpleOrder(), 'sylius_order_shipping', 'ship'));
        $this->subscriber->onWinzouTransition(self::winzouEvent(new \stdClass(), 'sylius_order_checkout', 'complete'));

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testRefusedOrderIsResentWhenPaid(): void
    {
        $accept = false;
        $sent = 0;
        $sender = $this->createMock(WebhookSenderInterface::class);
        $sender->method('sendBatch')->willReturnCallback(function () use (&$accept, &$sent) {
            ++$sent;
            return $accept;
        });
        $queue = new WebhookEventQueue($sender, null, new ArrayAdapter());
        $subscriber = new OrderCompleteSubscriber($queue, $this->requestStack, $this->logger);
        $order = $this->simpleOrder();

        $subscriber->onOrderComplete(new CompletedEvent($order, new Marking()));
        $queue->flush();
        $this->assertSame(1, $sent);

        $accept = true;
        $subscriber->onWinzouTransition(self::winzouEvent($order, 'sylius_order_payment', 'pay'));
        $queue->flush();
        $this->assertSame(2, $sent);

        $subscriber->onOrderPaid(new CompletedEvent($order, new Marking()));
        $queue->flush();
        $this->assertSame(2, $sent);
    }
}
