<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

class WebhookEventQueueTest extends TestCase
{
    private WebhookSenderInterface $webhookSender;
    private WebhookEventQueue $queue;

    protected function setUp(): void
    {
        $this->webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->queue = new WebhookEventQueue($this->webhookSender);
    }

    public function testQueueAndFlush(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.updated', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->assertTrue($this->queue->hasPending());
        $this->queue->flush();
        $this->assertFalse($this->queue->hasPending());
    }

    public function testFlushWithNoPendingEventsDoesNothing(): void
    {
        $this->webhookSender->expects($this->never())->method('sendBatch');

        $this->queue->flush();
    }

    public function testDeduplicatesBySameKey(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->queue->flush();
    }

    public function testPreservesCreatedOverUpdated(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.created', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.created', 'data' => ['identification_number' => 'product-1']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->queue->flush();
    }

    public function testDeleteEventIsNeverOverwritten(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.deleted', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->queue->flush();
    }

    public function testFullEventSupersedesAvailabilityQueuedFirst(): void
    {
        // Admin pure-stock save: the Doctrine flush queues the lightweight
        // availability event FIRST, then the resource post_update queues the
        // full event. Only the full event must be sent.
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.updated', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.availability', 'data' => ['identification_number' => 'variation-10']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $this->queue->flush();
    }

    public function testFullEventSupersedesAvailabilityQueuedSecond(): void
    {
        // Reverse order: a full event is already queued and a late
        // availability event for the same key must be dropped.
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.updated', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'variation-10']],
        ]);
        $this->queue->queue([
            ['type' => 'product.availability', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $this->queue->flush();
    }

    public function testAvailabilityEventSurvivesWithoutFullEvent(): void
    {
        // Order-driven decrement: no resource event, so the availability
        // event is the only one queued and must be sent.
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.availability', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.availability', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $this->queue->flush();
    }

    public function testDifferentKeysAreNotDeduplicated(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(2, $events);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-2']],
        ]);

        $this->queue->flush();
    }

    public function testSubscribesToMessengerWorkerEventsWhenAvailable(): void
    {
        $subscribed = WebhookEventQueue::getSubscribedEvents();

        $this->assertArrayHasKey(WorkerMessageHandledEvent::class, $subscribed);
        $this->assertSame(['flushOnMessageHandled', -100], $subscribed[WorkerMessageHandledEvent::class]);
        $this->assertArrayHasKey(WorkerMessageFailedEvent::class, $subscribed);
        $this->assertSame(['discardOnMessageFailed', -100], $subscribed[WorkerMessageFailedEvent::class]);
    }

    public function testFlushesAfterAsyncMessageHandled(): void
    {
        // Async transport: a stock decrement queued while handling the message
        // must be sent when the message is handled, not held until the worker
        // process terminates.
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.availability', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.availability', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $event = new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async');
        $this->queue->flushOnMessageHandled($event);

        $this->assertFalse($this->queue->hasPending());
    }

    public function testDiscardsPendingEventsWhenMessageFails(): void
    {
        // The handler's transaction is rolled back, so the queued change never
        // persisted and must not be emitted.
        $this->webhookSender->expects($this->never())->method('sendBatch');

        $this->queue->queue([
            ['type' => 'product.availability', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $event = new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \RuntimeException('boom'));
        $this->queue->discardOnMessageFailed($event);

        $this->assertFalse($this->queue->hasPending());
        $this->queue->flush();
    }

    public function testFlushCatchesSenderExceptions(): void
    {
        $this->webhookSender->method('sendBatch')->willThrowException(new \RuntimeException('Connection failed'));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->queue->flush();
        $this->assertFalse($this->queue->hasPending());
    }
}
