<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Queues webhook events during an HTTP request and flushes them
 * on kernel.terminate, after the response has been sent to the client.
 *
 * Deduplicates events by identification_number so that multiple variant
 * updates in one request only produce a single parent sync.
 * Preserves 'created' type over 'updated' when deduplicating.
 *
 * Precedence: a full product event (product.created/updated/deleted) always
 * supersedes a lightweight `product.availability` event for the same
 * identification_number, regardless of queue order. In Sylius's
 * ResourceController the Doctrine flush (VariantStockDoctrineListener →
 * product.availability) runs BEFORE the resource post_update event
 * (ProductEventSubscriber → full product event), so on a pure-stock admin
 * save the availability event is queued first and the full event must drop
 * it. Order-driven decrements have no resource event, so the availability
 * event survives and is sent.
 *
 * Console commands normally bypass the queue (they call sendBatch directly
 * for synchronous feedback), but if any code path queues events during a
 * console run we still flush on console.terminate as a safety net.
 *
 * Async Messenger: when stock-affecting operations are processed through an
 * async transport, the long-running `messenger:consume` worker never reaches
 * kernel.terminate or console.terminate per message, so events queued during
 * a message would stack up until the worker stops (or be lost if it is
 * killed). To cover that, we flush after each successfully handled message and
 * discard pending events when a message fails — a failed handler's Doctrine
 * transaction is rolled back, so the queued change never persisted and must
 * not be emitted. These hooks are only registered when symfony/messenger is
 * installed (it is an optional dependency).
 *
 * Sending: product payloads are built at flush, once per product however many
 * times it was saved in the request (queueBuild). order.completed goes first
 * and is never skipped; one Emporiqa refuses is kept for 30 days and sent
 * again on the order's next paid status (retryRefusedOrder). The rest goes
 * in batches of at most BATCH_SIZE events, a product's parent and variations
 * kept together, and after one failed batch the rest of this flush is
 * dropped (the next full sync restores it) instead of waiting on every batch.
 */
class WebhookEventQueue implements EventSubscriberInterface
{
    /** @var array<string, array{type: string, data: array}> */
    private array $pendingEvents = [];

    public const BATCH_SIZE = 50;

    private const ORDER_RETRY_TTL_SECONDS = 2592000;

    /** @var array<string, string> First event type per dedup key */
    private array $firstTypes = [];

    /** @var array<string, int> The queue() call each dedup key arrived with, to keep a product's events in one batch */
    private array $groups = [];

    private int $groupSeq = 0;

    /** @var array<string, array{build: callable, created: bool}> */
    private array $pendingBuilds = [];

    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private ?LoggerInterface $logger = null,
        private ?CacheItemPoolInterface $cache = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::TERMINATE => ['flush', -100],
            ConsoleEvents::TERMINATE => ['flushOnConsoleTerminate', -100],
        ];

        if (class_exists(WorkerMessageHandledEvent::class)) {
            $events[WorkerMessageHandledEvent::class] = ['flushOnMessageHandled', -100];
            $events[WorkerMessageFailedEvent::class] = ['discardOnMessageFailed', -100];
        }

        return $events;
    }

    /**
     * Defer building a payload to the flush. The same key queued again keeps
     * the latest builder, and `created` once any call said so.
     *
     * @param callable(bool $created): array $build returns the events
     */
    public function queueBuild(string $key, callable $build, bool $created = false): void
    {
        $created = $created || ($this->pendingBuilds[$key]['created'] ?? false);
        $this->pendingBuilds[$key] = ['build' => $build, 'created' => $created];
    }

    public function queue(array $events): void
    {
        $group = ++$this->groupSeq;
        foreach ($events as $event) {
            $key = $this->dedupKey($event);
            $this->groups[$key] ??= $group;

            $incomingIsAvailability = ($event['type'] ?? '') === 'product.availability';
            $existingType = ($this->pendingEvents[$key]['type'] ?? null);

            // A full product event always supersedes an availability event:
            //  - incoming availability while a full event is queued → drop it.
            //  - incoming full event while an availability is queued → let it
            //    overwrite below (and reset firstTypes so availability never
            //    leaks back in as the "first" type).
            if ($incomingIsAvailability && $existingType !== null && $existingType !== 'product.availability') {
                continue;
            }
            if (!$incomingIsAvailability && $existingType === 'product.availability') {
                unset($this->firstTypes[$key]);
            }

            if (!isset($this->firstTypes[$key])) {
                $this->firstTypes[$key] = $event['type'];
            }

            // Delete always wins — never overwrite a delete with update/create
            if ($existingType !== null && str_ends_with($existingType, '.deleted')) {
                continue;
            }

            $type = $event['type'];
            // Preserve 'created' over 'updated' when deduplicating
            if (str_ends_with($this->firstTypes[$key], '.created') && str_ends_with($type, '.updated')) {
                $type = $this->firstTypes[$key];
            }

            $event['type'] = $type;
            $this->pendingEvents[$key] = $event;
        }
    }

    /**
     * Events without an identification_number (e.g. order.completed) must
     * not all collapse onto one shared key and overwrite each other. Key
     * them by type + order_id so only true duplicates dedup; events with
     * neither identifier get a unique key and are never collapsed.
     */
    private function dedupKey(array $event): string
    {
        $identificationNumber = (string) ($event['data']['identification_number'] ?? '');
        if ($identificationNumber !== '') {
            return $identificationNumber;
        }

        $type = (string) ($event['type'] ?? '');
        $orderId = (string) ($event['data']['order_id'] ?? '');
        if ($orderId !== '') {
            return $type . ':' . $orderId;
        }

        return uniqid($type . ':', true);
    }

    public function flush(): void
    {
        $this->runBuilds();
        if (empty($this->pendingEvents)) {
            return;
        }

        $events = $this->pendingEvents;
        $groups = $this->groups;
        $this->clear();

        $orders = [];
        $rest = [];
        foreach ($events as $key => $event) {
            if (($event['type'] ?? '') === 'order.completed') {
                $orders[] = $event;
            } else {
                $rest[$key] = $event;
            }
        }

        foreach (array_chunk($orders, self::BATCH_SIZE) as $batch) {
            if (!$this->send($batch)) {
                $this->rememberRefusedOrders($batch);
            }
        }

        $batches = $this->batches($rest, $groups);
        foreach ($batches as $i => $batch) {
            if (!$this->send($batch)) {
                $skipped = array_sum(array_map('count', array_slice($batches, $i + 1)));
                if ($skipped > 0) {
                    $this->logger?->warning('Emporiqa did not accept a webhook batch; the remaining product and page webhooks of this request were not sent. Run emporiqa:sync:all to catch up.', [
                        'skipped_events' => $skipped,
                    ]);
                }
                break;
            }
        }
    }

    /**
     * Send the stored order.completed of an order Emporiqa refused earlier,
     * if any. Called on the order's next paid status.
     */
    public function retryRefusedOrder(string $orderId): void
    {
        if ($this->cache === null || $orderId === '') {
            return;
        }
        try {
            $key = $this->orderRetryKey($orderId);
            $item = $this->cache->getItem($key);
            if (!$item->isHit() || !is_array($item->get())) {
                return;
            }
            $this->queue([$item->get()]);
            // Removed now; a second refusal stores it again at flush.
            $this->cache->deleteItem($key);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to requeue a refused order.completed webhook', ['error' => $e->getMessage()]);
        }
    }

    private function runBuilds(): void
    {
        $builds = $this->pendingBuilds;
        $this->pendingBuilds = [];
        foreach ($builds as $key => $build) {
            try {
                $this->queue(($build['build'])($build['created']));
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to build a queued webhook payload', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Pack whole groups (one product's parent and variations) into batches of
     * at most BATCH_SIZE events; a group larger than that is split.
     *
     * @param array<string, array> $events
     * @param array<string, int> $groups
     *
     * @return list<list<array>>
     */
    private function batches(array $events, array $groups): array
    {
        $byGroup = [];
        foreach ($events as $key => $event) {
            $byGroup[$groups[$key] ?? 0][] = $event;
        }

        $batches = [];
        $current = [];
        foreach ($byGroup as $members) {
            if ($current !== [] && count($current) + count($members) > self::BATCH_SIZE) {
                $batches[] = $current;
                $current = [];
            }
            foreach ($members as $event) {
                if (count($current) >= self::BATCH_SIZE) {
                    $batches[] = $current;
                    $current = [];
                }
                $current[] = $event;
            }
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    private function send(array $batch): bool
    {
        try {
            return $this->webhookSender->sendBatch($batch);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to flush webhook event queue', [
                'events_count' => count($batch),
                'events' => array_map(
                    fn (array $ev) => ($ev['type'] ?? '?') . ':' . ($ev['data']['identification_number'] ?? $ev['data']['order_id'] ?? '?'),
                    $batch,
                ),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function rememberRefusedOrders(array $batch): void
    {
        if ($this->cache === null) {
            return;
        }
        foreach ($batch as $event) {
            $orderId = (string) ($event['data']['order_id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            try {
                $item = $this->cache->getItem($this->orderRetryKey($orderId));
                $item->set($event);
                $item->expiresAfter(self::ORDER_RETRY_TTL_SECONDS);
                $this->cache->save($item);
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to keep a refused order.completed webhook for retry', ['error' => $e->getMessage()]);
            }
        }
    }

    private function orderRetryKey(string $orderId): string
    {
        return 'emporiqa_order_retry_' . hash('sha256', $orderId);
    }

    private function clear(): void
    {
        $this->pendingEvents = [];
        $this->firstTypes = [];
        $this->groups = [];
    }

    /**
     * Defensive flush triggered on console.terminate. Mirrors the HTTP
     * kernel.terminate hook so any events queued during a CLI run (e.g. an
     * import script that triggers Doctrine listeners) still reach Emporiqa.
     */
    public function flushOnConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->flush();
    }

    /**
     * Flush after an async message is handled. In a long-running worker this
     * is the only reliable per-message flush point — kernel/console.terminate
     * fire only when the worker process itself ends.
     */
    public function flushOnMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->flush();
    }

    /**
     * Drop events queued while handling a message that failed. The handler's
     * Doctrine transaction is rolled back, so the stock change never persisted
     * and emitting the webhook would report a change that did not happen.
     */
    public function discardOnMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->pendingBuilds = [];
        $this->clear();
    }

    public function hasPending(): bool
    {
        return !empty($this->pendingEvents) || !empty($this->pendingBuilds);
    }

    /**
     * Returns true when an event for the given identification_number is
     * already queued. Used by the stock listener to suppress a lightweight
     * product.availability event when a full product update is already
     * pending for the same variant/product in this request.
     */
    public function hasPendingFor(string $identificationNumber): bool
    {
        return isset($this->pendingEvents[$identificationNumber]) || isset($this->pendingBuilds[$identificationNumber]);
    }
}
