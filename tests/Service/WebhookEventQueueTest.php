<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    public function testOrderEventsForDifferentOrdersDoNotCollide(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(2, $events);
                $orderIds = array_column(array_column($events, 'data'), 'order_id');
                $this->assertSame(['000000001', '000000002'], $orderIds);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'order.completed', 'data' => ['order_id' => '000000001', 'total' => 10.0]],
        ]);
        $this->queue->queue([
            ['type' => 'order.completed', 'data' => ['order_id' => '000000002', 'total' => 20.0]],
        ]);

        $this->queue->flush();
    }

    public function testDuplicateOrderEventForSameOrderIsDeduplicated(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('000000001', $events[0]['data']['order_id']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'order.completed', 'data' => ['order_id' => '000000001']],
        ]);
        $this->queue->queue([
            ['type' => 'order.completed', 'data' => ['order_id' => '000000001']],
        ]);

        $this->queue->flush();
    }

    public function testOrderEventDoesNotCollideWithProductEvent(): void
    {
        $sent = [];
        $this->webhookSender
            ->expects($this->exactly(2))
            ->method('sendBatch')
            ->willReturnCallback(function (array $events) use (&$sent) {
                $sent[] = array_column($events, 'type');
                return true;
            });

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);
        $this->queue->queue([
            ['type' => 'order.completed', 'data' => ['order_id' => '000000001']],
        ]);

        $this->queue->flush();

        // order.completed goes first, in its own batch.
        $this->assertSame([['order.completed'], ['product.updated']], $sent);
    }

    public function testEventsWithoutAnyIdentifierAreNeverCollapsed(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(2, $events);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'custom.event', 'data' => ['payload' => 'a']],
        ]);
        $this->queue->queue([
            ['type' => 'custom.event', 'data' => ['payload' => 'b']],
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

    /**
     * @return list<array> one product's parent and variations, as format() returns them
     */
    private static function product(int $id, int $variations): array
    {
        $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-' . $id]]];
        for ($i = 1; $i <= $variations; ++$i) {
            $events[] = ['type' => 'product.updated', 'data' => ['identification_number' => 'variation-' . $id . '-' . $i]];
        }

        return $events;
    }

    public function testDeferredBuildRunsOncePerProductAtFlush(): void
    {
        $builds = 0;
        $build = function (bool $created) use (&$builds): array {
            ++$builds;
            $events = self::product(1, 2);
            if ($created) {
                foreach ($events as &$event) {
                    $event['type'] = 'product.created';
                }
            }

            return $events;
        };
        $this->webhookSender->expects($this->once())->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(3, $events);
                $this->assertSame('product.created', $events[0]['type']);
                return true;
            }))
            ->willReturn(true);

        // Created, then two variant saves in the same request.
        $this->queue->queueBuild('product-1', $build, true);
        $this->queue->queueBuild('product-1', $build);
        $this->queue->queueBuild('product-1', $build);

        $this->assertSame(0, $builds);
        $this->assertTrue($this->queue->hasPendingFor('product-1'));
        $this->queue->flush();
        $this->assertSame(1, $builds);
    }

    public function testBatchesStayUnderFiftyAndKeepAProductTogether(): void
    {
        $batches = [];
        $this->webhookSender->method('sendBatch')->willReturnCallback(function (array $events) use (&$batches) {
            $batches[] = array_column(array_column($events, 'data'), 'identification_number');
            return true;
        });

        // 30 + 30 events: the second product does not fit beside the first.
        $this->queue->queue(self::product(1, 29));
        $this->queue->queue(self::product(2, 29));
        $this->queue->queue(self::product(3, 4));
        $this->queue->flush();

        $this->assertCount(2, $batches);
        $this->assertCount(30, $batches[0]);
        $this->assertSame('product-1', $batches[0][0]);
        $this->assertSame('product-2', $batches[1][0]);
        $this->assertCount(35, $batches[1]);
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(WebhookEventQueue::BATCH_SIZE, count($batch));
        }
    }

    public function testAProductLargerThanABatchIsSplit(): void
    {
        $sizes = [];
        $this->webhookSender->method('sendBatch')->willReturnCallback(function (array $events) use (&$sizes) {
            $sizes[] = count($events);
            return true;
        });

        $this->queue->queue(self::product(1, 119));
        $this->queue->flush();

        $this->assertSame([50, 50, 20], $sizes);
    }

    public function testAfterOneFailedBatchTheRestOfTheFlushIsDropped(): void
    {
        $this->webhookSender->expects($this->once())->method('sendBatch')->willReturn(false);

        $this->queue->queue(self::product(1, 49));
        $this->queue->queue(self::product(2, 49));
        $this->queue->queue(self::product(3, 49));
        $this->queue->flush();

        $this->assertFalse($this->queue->hasPending());
    }

    public function testOrderCompletedIsSentFirstAndNeverSkipped(): void
    {
        $types = [];
        $this->webhookSender->method('sendBatch')->willReturnCallback(function (array $events) use (&$types) {
            $types[] = array_column($events, 'type');
            return false;
        });

        $this->queue->queue(self::product(1, 0));
        $this->queue->queue([['type' => 'order.completed', 'data' => ['order_id' => '000000001']]]);
        $this->queue->queue([['type' => 'order.completed', 'data' => ['order_id' => '000000002']]]);
        $this->queue->flush();

        // Orders first, even though every send fails; then one product batch.
        $this->assertSame([['order.completed', 'order.completed'], ['product.updated']], $types);
    }

    public function testRefusedOrderIsSentAgainOnItsNextPaidStatus(): void
    {
        $cache = new ArrayAdapter();
        $sent = [];
        $accept = false;
        $sender = $this->createMock(WebhookSenderInterface::class);
        $sender->method('sendBatch')->willReturnCallback(function (array $events) use (&$sent, &$accept) {
            $sent[] = $events;
            return $accept;
        });
        $queue = new WebhookEventQueue($sender, null, $cache);
        $order = ['type' => 'order.completed', 'data' => ['order_id' => '000000007', 'emporiqa_session_id' => 'sid-1']];

        $queue->queue([$order]);
        $queue->flush();

        $accept = true;
        $queue->retryRefusedOrder('000000007');
        $queue->flush();
        $this->assertSame([[$order], [$order]], $sent);

        // Accepted now, so a later paid status sends nothing.
        $queue->retryRefusedOrder('000000007');
        $queue->flush();
        $this->assertCount(2, $sent);
    }

    public function testAcceptedOrderIsNotKeptForRetry(): void
    {
        $this->webhookSender->expects($this->once())->method('sendBatch')->willReturn(true);
        $queue = new WebhookEventQueue($this->webhookSender, null, new ArrayAdapter());

        $queue->queue([['type' => 'order.completed', 'data' => ['order_id' => '1']]]);
        $queue->flush();
        $queue->retryRefusedOrder('1');

        $this->assertFalse($queue->hasPending());
    }

    public function testAFailingBuildIsLoggedAndTheRestStillSent(): void
    {
        $this->webhookSender->expects($this->once())->method('sendBatch')
            ->with($this->callback(fn (array $events) => $events[0]['data']['identification_number'] === 'product-2'))
            ->willReturn(true);

        $this->queue->queueBuild('product-1', fn () => throw new \RuntimeException('gone'));
        $this->queue->queueBuild('product-2', fn () => self::product(2, 0));
        $this->queue->flush();
    }

    public function testDiscardOnMessageFailedDropsPendingBuilds(): void
    {
        $this->webhookSender->expects($this->never())->method('sendBatch');
        $this->queue->queueBuild('product-1', fn () => self::product(1, 0));

        $this->queue->discardOnMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \RuntimeException('boom')),
        );
        $this->queue->flush();
    }
}
