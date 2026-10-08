<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    public function testWithoutACachePoolTheRestOfAFailedFlushIsDropped(): void
    {
        $this->webhookSender->expects($this->once())->method('sendBatch')->willReturn(false);

        $this->queue->queue(self::product(1, 49));
        $this->queue->queue(self::product(2, 49));
        $this->queue->queue(self::product(3, 49));
        $this->queue->flush();

        $this->assertFalse($this->queue->hasPending());
    }

    private static function ev(string $ident, string $v = '', string $type = 'product.updated'): array
    {
        return ['type' => $type, 'data' => ['identification_number' => $ident, 'v' => $v]];
    }

    /** Move every kept item's last try (and first keep) back, as if time passed. */
    private static function age(ArrayAdapter $cache, int $seconds): void
    {
        $item = $cache->getItem('emporiqa_webhook_retry');
        if (!$item->isHit()) {
            return;
        }
        $kept = $item->get();
        foreach ($kept as &$entry) {
            $entry['last_try'] -= $seconds;
            $entry['at'] -= $seconds;
        }
        unset($entry);
        $cache->save($item->set($kept));
    }

    /**
     * Emporiqa down or refusing the store (5xx, 429, 401, no answer): the
     * items of the failed batch and the rest of the flush are kept, and go
     * with a later flush, built again from the shop's current state, after
     * that flush's own changes.
     */
    public function testAFailedBatchIsKeptAndSentAgainAsItIsNow(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1', 'product-2' => 'w1']);
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);

        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->queue([self::ev('product-2', 'w1')]);
        $queue->flush();
        $this->assertSame(2, $queue->retainedCount());

        $db->state['product-1'] = 'v2';
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $queue->queue([self::ev('page-1', 'p')]);
        $queue->flush();

        $this->assertSame(['page-1:p', 'product-1:v2', 'product-2:w1'], $sender->sent(1));
        $this->assertSame(0, $queue->retainedCount());
    }

    /**
     * The race the 1.11.1 review found: request A builds X v1 and fails
     * slowly; meanwhile B sends X v2 and Emporiqa accepts it; A keeps X.
     * A later flush must send X as it is now (v2), never A's older v1.
     */
    public function testAKeptItemNeverOverwritesANewerChangeSentMeanwhile(): void
    {
        $cache = new ArrayAdapter(0, false);
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $senderA = new StatusWebhookSender([], 503, false);
        $a = new WebhookEventQueue($senderA, null, $cache, $db);
        $a->queue([self::ev('product-1', 'v1')]);
        $senderB = new StatusWebhookSender([], null);
        $b = new WebhookEventQueue($senderB, null, $cache, $db);
        $senderA->during = function () use ($b, $db): void {
            $db->state['product-1'] = 'v2';
            $b->queue([self::ev('product-1', 'v2')]);
            $b->flush();
        };
        $a->flush();

        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $senderC = new StatusWebhookSender([], null);
        $c = new WebhookEventQueue($senderC, null, $cache, $db);
        $c->queue([self::ev('product-2', 'x')]);
        $c->flush();

        $this->assertSame(['product-1:v2'], $senderB->sent(0));
        $this->assertNotContains('product-1:v1', $senderC->sent(0));
        $this->assertSame(['product-2:x', 'product-1:v2'], $senderC->sent(0));
    }

    public function testAKeptItemDeletedSinceIsSentAsDeleted(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();

        unset($db->state['product-1']);
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $queue->queue([self::ev('page-1', 'p')]);
        $queue->flush();

        $this->assertSame(['product.deleted'], array_column($sender->batches[2], 'type'));
    }

    /** A kept item this flush sends anyway is not built again. */
    public function testAKeptItemSentFreshIsNotBuiltAgain(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();

        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $queue->queue([self::ev('product-1', 'v3')]);
        $queue->flush();

        $this->assertSame(['product-1:v3'], $sender->sent(1));
        $this->assertSame([], $db->asked);
        $this->assertSame(0, $queue->retainedCount());
    }

    /**
     * Review point 1: this request's own changes are sent before any kept
     * item is built, so a slow or dying rebuild never costs them.
     */
    public function testOwnEventsAreSentBeforeKeptItemsAreBuilt(): void
    {
        $cache = new ArrayAdapter();
        $db = new class () implements \Emporiqa\SyliusPlugin\Service\CurrentStateBuilderInterface {
            public ?StatusWebhookSender $sender = null;
            public int $sentBeforeBuild = -1;

            public function build(array $identificationNumbers, array &$failed = []): array
            {
                $this->sentBeforeBuild = count($this->sender->batches);
                throw new \RuntimeException('memory limit');
            }
        };
        $sender = new StatusWebhookSender([false], 503);
        $db->sender = $sender;
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();

        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $queue->queue([self::ev('page-1', 'p')]);
        $queue->flush();

        $this->assertSame(['page-1:p'], $sender->sent(1));
        $this->assertSame(2, $db->sentBeforeBuild, 'the own batch went before the build started');
        $this->assertSame(1, $queue->retainedCount(), 'the kept item stays, tried again later');
    }

    /** Review point 1: at most RETRY_BATCH kept items are built per flush, oldest first. */
    public function testAtMostABatchOfKeptItemsIsBuiltPerFlushOldestFirst(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState([]);
        $kept = [];
        for ($i = 1; $i <= 60; ++$i) {
            $kept['product-' . $i] = ['at' => time() - 10000 + $i, 'attempts' => 0, 'token' => 't' . $i, 'last_try' => time() - 10000];
        }
        $cache->save($cache->getItem('emporiqa_webhook_retry')->set($kept));
        $queue = new WebhookEventQueue(new StatusWebhookSender([], null), null, $cache, $db);

        $queue->queue([self::ev('page-1')]);
        $queue->flush();

        $this->assertSame(WebhookEventQueue::RETRY_BATCH, count($db->asked));
        $this->assertSame('product-1', $db->asked[0]);
        $this->assertSame(60 - WebhookEventQueue::RETRY_BATCH, $queue->retainedCount());
    }

    /** Review point 1: an item tried in the last RETRY_PAUSE_SECONDS is not built again. */
    public function testAKeptItemIsNotRetriedWithinThePause(): void
    {
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $queue = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, new ArrayAdapter(), $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();

        $queue->queue([self::ev('page-1')]);
        $queue->flush();

        $this->assertSame(0, $db->calls);
        $this->assertSame(1, $queue->retainedCount());
    }

    /**
     * Review point 2: the attempt cap counts tries spaced by the pause, not
     * flushes, so a busy shop in a short outage does not drop its changes.
     */
    public function testManyFailedFlushesInAShortOutageDropNothing(): void
    {
        $cache = new ArrayAdapter();
        $sender = new StatusWebhookSender([], 503, false);
        $queue = new WebhookEventQueue($sender, null, $cache, new FakeCurrentState(['product-1' => 'v1']));
        for ($i = 0; $i < 3 * WebhookEventQueue::RETRY_MAX_ATTEMPTS; ++$i) {
            $queue->queue([self::ev('product-1', 'v1')]);
            $queue->flush();
        }

        $this->assertSame(0, $cache->getItem('emporiqa_webhook_retry')->get()['product-1']['attempts']);
    }

    /** One item Emporiqa keeps failing for hours is dropped, with a warning. */
    public function testAnItemThatKeepsFailingIsDroppedAfterMaxAttempts(): void
    {
        $cache = new ArrayAdapter();
        $sender = new StatusWebhookSender([], 503, false);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });
        $queue = new WebhookEventQueue($sender, $logger, $cache, new FakeCurrentState(['product-1' => 'v1']));
        for ($i = 0; $i <= WebhookEventQueue::RETRY_MAX_ATTEMPTS; ++$i) {
            $queue->queue([self::ev('product-1', 'v1')]);
            $queue->flush();
            self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        }

        $this->assertSame(0, $queue->retainedCount());
        $this->assertNotEmpty(array_filter($warnings, fn (string $m) => str_contains($m, 'dropped')));
    }

    /**
     * Review point 3: one item whose state cannot be built is left out and
     * tried again later; the other kept items still go.
     */
    public function testOneItemThatCannotBeBuiltDoesNotHoldUpTheOthers(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1', 'product-2' => 'w1']);
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1'), self::ev('product-2', 'w1')]);
        $queue->flush();

        $db->broken = ['product-1'];
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $queue->queue([self::ev('page-1', 'p')]);
        $queue->flush();

        $this->assertSame(['page-1:p', 'product-2:w1'], $sender->sent(1));
        $kept = $cache->getItem('emporiqa_webhook_retry')->get();
        $this->assertSame(['product-1'], array_keys($kept));
        $this->assertSame(1, $kept['product-1']['attempts'], 'the failed build counts an attempt');
    }

    /** A builder that dies counts an attempt and pauses the items, never a retry on every flush. */
    public function testABuilderThatThrowsIsNotCalledOnEveryFlush(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState([]);
        $queue = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);
        $db->broken = ['product-1'];

        for ($i = 0; $i < 30; ++$i) {
            $queue->queue([self::ev('page-' . ($i + 1))]);
            $queue->flush();
        }

        $this->assertSame(1, $db->calls);
        $this->assertSame(1, $queue->retainedCount());
    }

    /** No retry while Emporiqa refuses this request's own changes. */
    public function testNoRetryWhileTheOwnSendFails(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $queue = new WebhookEventQueue(new StatusWebhookSender([], 503, false), null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);

        $queue->queue([self::ev('page-1')]);
        $queue->flush();

        $this->assertSame(0, $db->calls);
        $this->assertSame(2, $queue->retainedCount());
    }

    /**
     * A batch refused for its content (400, 413) would be refused again: it
     * is not kept, and the rest of the flush is still sent.
     */
    public function testABatchRefusedForItsContentIsNotKept(): void
    {
        $sender = new StatusWebhookSender([false, true], 400);
        $queue = new WebhookEventQueue($sender, null, new ArrayAdapter(), new FakeCurrentState([]));

        $queue->queue(self::product(1, 49));
        $queue->queue(self::product(2, 49));
        $queue->flush();

        $this->assertCount(2, $sender->batches);
        $this->assertSame(0, $queue->retainedCount());
    }

    public function testAWrongSecretKeepsTheItems(): void
    {
        $sender = new StatusWebhookSender([false], 401);
        $queue = new WebhookEventQueue($sender, null, new ArrayAdapter(), new FakeCurrentState([]));

        $queue->queue([self::ev('product-1'), self::ev('variation-11')]);
        $queue->flush();

        $this->assertSame(2, $queue->retainedCount());
    }

    /** Without a builder nothing can be sent again safely, so nothing is kept. */
    public function testWithoutABuilderNothingIsKept(): void
    {
        $queue = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, new ArrayAdapter());

        $queue->queue([self::ev('product-1')]);
        $queue->flush();

        $this->assertSame(0, $queue->retainedCount());
    }

    /** At most RETRY_MAX_ITEMS are kept, the oldest dropped first. */
    public function testKeptItemsAreBounded(): void
    {
        $cache = new ArrayAdapter();
        $sender = new StatusWebhookSender([], 503, false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $queue = new WebhookEventQueue($sender, $logger, $cache, new FakeCurrentState([]));
        $old = [];
        for ($i = 1; $i <= WebhookEventQueue::RETRY_MAX_ITEMS; ++$i) {
            $old['product-' . $i] = ['at' => time() - 1000 + $i, 'attempts' => 0, 'token' => 't', 'last_try' => time()];
        }
        $cache->save($cache->getItem('emporiqa_webhook_retry')->set($old));

        $queue->queue([self::ev('page-1')]);
        $queue->flush();

        $this->assertSame(WebhookEventQueue::RETRY_MAX_ITEMS, $queue->retainedCount());
        $kept = array_keys($cache->getItem('emporiqa_webhook_retry')->get());
        $this->assertNotContains('product-1', $kept);
        $this->assertContains('page-1', $kept);
    }

    public function testKeptItemsExpire(): void
    {
        $cache = new ArrayAdapter();
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();

        self::age($cache, WebhookEventQueue::RETRY_TTL_SECONDS + 1);

        $this->assertSame(0, $queue->retainedCount());
        $queue->queue([self::ev('page-1', 'p')]);
        $queue->flush();
        $this->assertSame(['page-1:p'], $sender->sent(1));
    }

    /** Kept items stay in the pool until a flush has sent them. */
    public function testAFlushThatFailsAgainKeepsTheItems(): void
    {
        $sender = new StatusWebhookSender([false, false], 503);
        $queue = new WebhookEventQueue($sender, null, new ArrayAdapter(), new FakeCurrentState(['product-1' => 'v1']));
        $queue->queue([self::ev('product-1', 'v1')]);
        $queue->flush();
        $queue->queue([self::ev('page-1')]);
        $queue->flush();

        $this->assertSame(2, $queue->retainedCount());
    }

    /** A retried item another request kept again meanwhile stays kept. */
    public function testAnItemKeptAgainMeanwhileIsNotForgotten(): void
    {
        $cache = new ArrayAdapter(0, false);
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $first = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, $cache, $db);
        $first->queue([self::ev('product-1', 'v1')]);
        $first->flush();
        self::age($cache, WebhookEventQueue::RETRY_PAUSE_SECONDS);

        $other = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, $cache, $db);
        $sender = new StatusWebhookSender([], null);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('page-1')]);
        // During the retry's send (the second batch), another request keeps product-1 again.
        $sender->answersDuring = [1 => function () use ($other): void {
            $other->queue([self::ev('product-1', 'v2')]);
            $other->flush();
        }];
        $queue->flush();

        $this->assertSame(['product-1'], array_keys($cache->getItem('emporiqa_webhook_retry')->get() ?? []));
    }

    /**
     * A request sends a kept item as its own change; while it sends,
     * another request fails and keeps that item again. The first request's
     * success must not take the item kept again out of the pool.
     */
    public function testAnOwnChangeSentDoesNotForgetTheSameItemKeptAgainMeanwhile(): void
    {
        $cache = new ArrayAdapter(0, false);
        $db = new FakeCurrentState(['product-1' => 'v1']);
        $first = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, $cache, $db);
        $first->queue([self::ev('product-1', 'v1')]);
        $first->flush();
        $tokenBefore = $cache->getItem('emporiqa_webhook_retry')->get()['product-1']['token'];

        $other = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, $cache, $db);
        $sender = new StatusWebhookSender([], null);
        $queue = new WebhookEventQueue($sender, null, $cache, $db);
        $queue->queue([self::ev('product-1', 'v2')]);
        $sender->during = function () use ($other): void {
            $other->queue([self::ev('product-1', 'v3')]);
            $other->flush();
        };
        $queue->flush();

        $kept = $cache->getItem('emporiqa_webhook_retry')->get() ?? [];
        $this->assertSame(['product-1:v2'], $sender->sent(0));
        $this->assertSame(['product-1'], array_keys($kept));
        $this->assertNotSame($tokenBefore, $kept['product-1']['token']);
    }

    /** Two flushes failing at once keep both requests' items. */
    public function testConcurrentFailuresKeepBothRequestsItems(): void
    {
        $cache = new ArrayAdapter(0, false);
        $db = new FakeCurrentState([]);
        $sa = new StatusWebhookSender([], 503, false);
        $b = new WebhookEventQueue(new StatusWebhookSender([], 503, false), null, $cache, $db);
        $a = new WebhookEventQueue($sa, null, $cache, $db);
        $a->queue([self::ev('product-1', 'a')]);
        $b->queue([self::ev('product-2', 'b')]);
        $sa->during = function () use ($b): void {
            $b->flush();
        };
        $a->flush();

        $this->assertSame(2, $a->retainedCount());
    }

    /** With symfony/lock installed, the pool is written under its lock. */
    public function testThePoolIsWrittenUnderTheLockWhenThereIsOne(): void
    {
        $lockFactory = new class () {
            public int $acquired = 0;
            public int $released = 0;

            public function createLock(string $resource, float $ttl): object
            {
                $factory = $this;

                return new class ($factory) {
                    public function __construct(private object $factory) {}

                    public function acquire(bool $blocking): bool
                    {
                        ++$this->factory->acquired;

                        return true;
                    }

                    public function release(): void
                    {
                        ++$this->factory->released;
                    }
                };
            }
        };
        $queue = new WebhookEventQueue(new StatusWebhookSender([false], 503), null, new ArrayAdapter(), new FakeCurrentState([]), $lockFactory);

        $queue->queue([self::ev('product-1')]);
        $queue->flush();

        $this->assertSame(1, $lockFactory->acquired);
        $this->assertSame(1, $lockFactory->released);
        $this->assertSame(1, $queue->retainedCount());
    }

    public function testForgetRetainedDropsOneKindOnly(): void
    {
        $sender = new StatusWebhookSender([false], 503);
        $queue = new WebhookEventQueue($sender, null, new ArrayAdapter(), new FakeCurrentState(['page-1' => 'p']));
        $queue->queue([self::ev('product-1'), self::ev('variation-3'), self::ev('page-1', 'p', 'page.updated')]);
        $queue->flush();

        $queue->forgetRetained('product.');

        $this->assertSame(1, $queue->retainedCount());
    }

    public function testOrdersAreNotKeptWithProductEvents(): void
    {
        $queue = new WebhookEventQueue(new StatusWebhookSender([], 503, false), null, new ArrayAdapter(), new FakeCurrentState([]));

        $queue->queue([['type' => 'order.completed', 'data' => ['order_id' => '1']]]);
        $queue->flush();

        $this->assertSame(0, $queue->retainedCount());
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

/**
 * Answers sendBatch() from a script (then $accept), and reports an HTTP
 * status like WebhookSender::getLastStatusCode().
 */
class StatusWebhookSender implements WebhookSenderInterface
{
    /** @var list<list<array>> */
    public array $batches = [];

    /** @param list<bool> $answers */
    public function __construct(private array $answers, private ?int $failStatus, public bool $accept = true) {}

    private ?int $status = null;

    /** @var callable|null runs once, inside the next send, before it answers */
    public $during = null;

    public bool $failed = false;

    /** @var array<int, callable> runs inside the send with that index (0-based, counted from now) */
    public array $answersDuring = [];

    private int $sends = 0;

    public function sendBatch(array $events): bool
    {
        if (isset($this->answersDuring[$this->sends])) {
            ($this->answersDuring[$this->sends])();
        }
        ++$this->sends;
        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }
        $this->batches[] = $events;
        $ok = $this->answers === [] ? ($this->failed ? false : $this->accept) : array_shift($this->answers);
        $this->status = $ok ? 202 : $this->failStatus;

        return $ok;
    }

    public function getLastStatusCode(): ?int
    {
        return $this->status;
    }

    /** @return list<string> ident:v of every event sent in batches from $from on */
    public function sent(int $from): array
    {
        $sent = [];
        foreach (array_slice($this->batches, $from) as $batch) {
            foreach ($batch as $event) {
                $sent[] = ($event['data']['identification_number'] ?? '') . ':' . ($event['data']['v'] ?? '');
            }
        }

        return $sent;
    }

    /** @return list<string> the identification numbers of the products/pages in batches from $from on, parents only */
    public function firstIdents(int $from): array
    {
        $idents = [];
        foreach (array_slice($this->batches, $from) as $batch) {
            foreach ($batch as $event) {
                $ident = $event['data']['identification_number'] ?? '';
                if (!str_starts_with($ident, 'variation-')) {
                    $idents[] = $ident;
                }
            }
        }

        return $idents;
    }

    public function send(string $event, array $data): bool
    {
        return $this->sendBatch([['type' => $event, 'data' => $data]]);
    }

    public function testConnection(): array
    {
        return ['success' => true];
    }

    public function sendDryRun(array $events): array
    {
        return ['success' => true];
    }

    public function getLastError(): ?string
    {
        return null;
    }

    public function buildFriendlyError(array $result): string
    {
        return '';
    }
}
