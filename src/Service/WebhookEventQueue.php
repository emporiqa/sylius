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
 * kept together. After one failed batch the rest of this flush is not tried
 * (Emporiqa is down or refusing the store, every batch would wait).
 *
 * What was not accepted is sent again with the next flush, but never as the
 * payload that failed: only the identification numbers are kept (in the
 * cache pool), and the payload is built again from the database when it is
 * sent (CurrentStateBuilderInterface). A retry therefore always sends the
 * item's state at that moment, so it can never overwrite a newer change
 * another request or a full sync sent meanwhile; an item deleted or
 * disabled since is sent as deleted. A kept number leaves the pool only once
 * a flush sent it and no other request kept it again in between. A batch
 * Emporiqa refuses for its content (HTTP 400 or 413) is not kept, since it
 * would be refused again.
 *
 * A flush sends its own events first, and retries kept items only after
 * Emporiqa accepted those: at most RETRY_BATCH, oldest first, each at most
 * once per RETRY_PAUSE_SECONDS. What is kept is bounded: RETRY_MAX_ITEMS
 * (oldest dropped first), RETRY_TTL_SECONDS, and RETRY_MAX_ATTEMPTS tries per
 * item (spaced by the pause, so hours of outage), so one item Emporiqa keeps
 * failing cannot hold up every flush.
 * Each drop is logged with a pointer to emporiqa:sync:all. The pool is
 * written under a lock when symfony/lock is installed (lock.factory).
 */
class WebhookEventQueue implements EventSubscriberInterface
{
    /** @var array<string, array{type: string, data: array}> */
    private array $pendingEvents = [];

    public const BATCH_SIZE = 50;

    private const ORDER_RETRY_TTL_SECONDS = 2592000;

    public const RETRY_MAX_ITEMS = 500;

    public const RETRY_TTL_SECONDS = 604800;

    public const RETRY_MAX_ATTEMPTS = 20;

    /** A kept item is tried again at most once per this many seconds. */
    public const RETRY_PAUSE_SECONDS = 600;

    /** Kept items built again and sent per flush, oldest first. */
    public const RETRY_BATCH = 25;

    private const KEPT_IDENT = '/^(product|variation|page)-\d{1,18}$/D';

    private const RETRY_KEY = 'emporiqa_webhook_retry';

    /** Refusals of the payload itself; sending it again cannot succeed. */
    private const REFUSED_FOR_GOOD = [400, 413];

    /** @var array<string, string> First event type per dedup key */
    private array $firstTypes = [];

    /** @var array<string, int> The queue() call each dedup key arrived with, to keep a product's events in one batch */
    private array $groups = [];

    private int $groupSeq = 0;

    /** @var array<string, array{build: callable, created: bool}> */
    private array $pendingBuilds = [];

    /**
     * @param CurrentStateBuilderInterface|\Closure|null $currentState the builder, or a closure returning it
     *        (lazy: the queue runs on every request, the builder is needed only to retry)
     * @param object|null $lockFactory a Symfony\Component\Lock\LockFactory, when symfony/lock is installed
     */
    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private ?LoggerInterface $logger = null,
        private ?CacheItemPoolInterface $cache = null,
        private CurrentStateBuilderInterface|\Closure|null $currentState = null,
        private ?object $lockFactory = null,
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

        // This request's own changes first, on their own: a retry of older
        // items must never cost them (a slow rebuild, a memory or time limit).
        $kept = $this->readKept();
        $done = [];
        $failed = [];
        $batches = $this->batches($rest, $groups);
        foreach ($batches as $i => $batch) {
            if ($this->send($batch)) {
                $done = array_merge($done, self::idents($batch));

                continue;
            }
            if ($this->refusedForGood()) {
                $this->logger?->error('Emporiqa refused a webhook batch for its content; it is not sent again.', [
                    'events_count' => count($batch),
                ]);
                $done = array_merge($done, self::idents($batch));

                continue;
            }
            foreach (array_slice($batches, $i) as $unsent) {
                $failed = array_merge($failed, self::idents($unsent));
            }

            break;
        }

        if (($kept !== [] && $done !== []) || $failed !== []) {
            $this->settleOwn($kept, $done, $failed);
        }
        // Emporiqa just took this request's changes: a good moment to retry.
        if ($kept !== [] && $failed === []) {
            $this->retryKept($done);
        }
    }

    /**
     * Forget kept items of one kind ('product.' for products and variations,
     * 'page.' for pages), once a full sync has sent everything again.
     */
    public function forgetRetained(string $typePrefix): void
    {
        $pattern = $typePrefix === 'page.' ? '/^page-/' : '/^(product|variation)-/';
        $this->withKept(function (array $stored) use ($pattern): array {
            return array_filter($stored, fn (string $ident): bool => preg_match($pattern, $ident) !== 1, \ARRAY_FILTER_USE_KEY);
        });
    }

    /** Number of products, variations and pages waiting to be sent again. */
    public function retainedCount(): int
    {
        return count($this->readKept());
    }

    /**
     * After this request's own send: what it sent leaves the pool (unless
     * another request kept it again since the pool was read), what it could
     * not send is kept. An item kept again counts one more attempt only when
     * its last try is older than RETRY_PAUSE_SECONDS, so the attempt cap
     * stands for hours of outage, not for a few busy minutes.
     *
     * @param array<string, array{at: int, attempts: int, token: string, last_try: int}> $read
     * @param list<string> $done
     * @param list<string> $failed
     */
    private function settleOwn(array $read, array $done, array $failed): void
    {
        $failed = array_values(array_unique(array_filter($failed, fn (string $i): bool => preg_match(self::KEPT_IDENT, $i) === 1)));
        if ($failed !== [] && ($this->cache === null || $this->currentState === null)) {
            $this->logger?->warning('Emporiqa did not accept a webhook batch; the product and page webhooks of this request were not sent. Run emporiqa:sync:all to catch up.', [
                'skipped_events' => count($failed),
            ]);
            $failed = [];
            if ($done === []) {
                return;
            }
        }

        $now = time();
        $dropped = 0;
        $this->withKept(function (array $stored) use ($read, $done, $failed, $now, &$dropped): array {
            foreach (array_unique($done) as $ident) {
                if (isset($read[$ident], $stored[$ident]) && $stored[$ident]['token'] === $read[$ident]['token']) {
                    unset($stored[$ident]);
                }
            }
            foreach ($failed as $ident) {
                $entry = $stored[$ident] ?? null;
                $attempts = $entry === null ? 0 : $entry['attempts'] + ($now - $entry['last_try'] >= self::RETRY_PAUSE_SECONDS ? 1 : 0);
                if ($attempts >= self::RETRY_MAX_ATTEMPTS) {
                    unset($stored[$ident]);
                    ++$dropped;

                    continue;
                }
                $stored[$ident] = [
                    'at' => $entry['at'] ?? $now,
                    'attempts' => $attempts,
                    'token' => bin2hex(random_bytes(8)),
                    'last_try' => $now,
                ];
            }
            if (count($stored) > self::RETRY_MAX_ITEMS) {
                uasort($stored, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
                $dropped += count($stored) - self::RETRY_MAX_ITEMS;
                $stored = array_slice($stored, -self::RETRY_MAX_ITEMS, null, true);
            }

            return $stored;
        });

        if ($failed !== []) {
            $this->logger?->warning('Emporiqa did not accept a webhook batch; the changed products and pages are kept and sent again, as they are then, with a later change.', [
                'kept_items' => count($failed),
            ]);
        }
        $this->warnDropped($dropped);
    }

    /**
     * Send again at most RETRY_BATCH kept items, oldest first, that were not
     * tried in the last RETRY_PAUSE_SECONDS, each built again from the shop's
     * current state. Each is marked tried (one more attempt) before it is
     * built, so a build that dies (a memory or time limit) pauses those items
     * instead of repeating on every flush; one that keeps failing for
     * RETRY_MAX_ATTEMPTS tries is dropped.
     *
     * @param list<string> $sentNow items this flush already sent
     */
    private function retryKept(array $sentNow): void
    {
        $builder = $this->currentState instanceof \Closure ? ($this->currentState)() : $this->currentState;
        if (!$builder instanceof CurrentStateBuilderInterface) {
            return;
        }

        $now = time();
        $chosen = [];
        $dropped = 0;
        $this->withKept(function (array $stored) use ($sentNow, $now, &$chosen, &$dropped): array {
            uasort($stored, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            foreach ($stored as $ident => $entry) {
                if (count($chosen) >= self::RETRY_BATCH) {
                    break;
                }
                if (in_array($ident, $sentNow, true) || $now - $entry['last_try'] < self::RETRY_PAUSE_SECONDS) {
                    continue;
                }
                if ($entry['attempts'] + 1 >= self::RETRY_MAX_ATTEMPTS) {
                    unset($stored[$ident]);
                    ++$dropped;

                    continue;
                }
                $token = bin2hex(random_bytes(8));
                $stored[$ident] = ['attempts' => $entry['attempts'] + 1, 'token' => $token, 'last_try' => $now] + $entry;
                $chosen[$ident] = $token;
            }

            return $stored;
        });
        $this->warnDropped($dropped);
        if ($chosen === []) {
            return;
        }

        $buildFailed = [];
        try {
            $events = $builder->build(array_keys($chosen), $buildFailed);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to build the kept webhook items again; they are tried again later', ['error' => $e->getMessage()]);

            return;
        }
        if ($buildFailed !== []) {
            $this->logger?->warning('Some kept webhook items could not be built again; they are tried again later', ['items' => count($buildFailed)]);
        }

        $byKey = [];
        foreach ($events as $event) {
            $byKey[$this->dedupKey($event)] = $event;
        }
        $sent = true;
        foreach ($this->batches($byKey, array_fill_keys(array_keys($byKey), 1)) as $batch) {
            if (!$this->send($batch) && !$this->refusedForGood()) {
                $sent = false;

                break;
            }
        }
        if (!$sent) {
            return;
        }

        // Sent: those items leave the pool, unless kept again meanwhile.
        $finished = array_diff_key($chosen, array_flip($buildFailed));
        $this->withKept(function (array $stored) use ($finished): array {
            foreach ($finished as $ident => $token) {
                if (($stored[$ident]['token'] ?? null) === $token) {
                    unset($stored[$ident]);
                }
            }

            return $stored;
        });
    }

    private function warnDropped(int $dropped): void
    {
        if ($dropped > 0) {
            $this->logger?->warning('Some changes could not be delivered to Emporiqa after many tries or too long; they were dropped. Run emporiqa:sync:all once Emporiqa accepts webhooks again.', [
                'dropped_items' => $dropped,
            ]);
        }
    }

    /**
     * The kept items still within RETRY_TTL_SECONDS (the expired ones are
     * removed the next time the pool is written).
     *
     * @return array<string, array{at: int, attempts: int, token: string, last_try: int}>
     */
    private function readKept(): array
    {
        if ($this->cache === null) {
            return [];
        }

        try {
            $item = $this->cache->getItem(self::RETRY_KEY);

            return $item->isHit() ? $this->unexpired($item->get()) : [];
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to read the kept webhook items', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Read, change and write the pool, under a lock when one is available;
     * without one the pool is read again right before the write, which
     * leaves only that instant for two requests to race.
     *
     * @param callable(array<string, array{at: int, attempts: int, token: string, last_try: int}>): array $change
     */
    private function withKept(callable $change): void
    {
        if ($this->cache === null) {
            return;
        }
        $lock = null;
        if ($this->lockFactory !== null && method_exists($this->lockFactory, 'createLock')) {
            try {
                $lock = $this->lockFactory->createLock(self::RETRY_KEY, 10.0);
                $lock->acquire(true);
            } catch (\Throwable $e) {
                $lock = null;
                $this->logger?->warning('Could not lock the kept webhook items', ['error' => $e->getMessage()]);
            }
        }

        try {
            $item = $this->cache->getItem(self::RETRY_KEY);
            $before = $item->isHit() ? $item->get() : null;
            $stored = $change($this->unexpired($before));
            if (is_array($before) && count($this->unexpired($before)) < count($before)) {
                $this->logger?->warning('Kept webhook items older than 7 days were dropped. Run emporiqa:sync:all.', [
                    'dropped_items' => count($before) - count($this->unexpired($before)),
                ]);
            }
            if ($stored === []) {
                $this->cache->deleteItem(self::RETRY_KEY);
            } else {
                $item->set($stored);
                $item->expiresAfter(self::RETRY_TTL_SECONDS);
                if (!$this->cache->save($item)) {
                    $this->logger?->error('Failed to keep webhook items for retry; run emporiqa:sync:all to catch up.', ['kept_items' => count($stored)]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to keep webhook items for retry; run emporiqa:sync:all to catch up.', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return array<string, array{at: int, attempts: int, token: string, last_try: int}>
     */
    private function unexpired(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $since = time() - self::RETRY_TTL_SECONDS;
        $kept = [];
        foreach ($stored as $ident => $entry) {
            if (is_string($ident) && preg_match(self::KEPT_IDENT, $ident) === 1 && is_array($entry) &&
                is_int($entry['at'] ?? null) && $entry['at'] >= $since &&
                is_int($entry['attempts'] ?? null) && is_string($entry['token'] ?? null)
            ) {
                $kept[$ident] = [
                    'at' => $entry['at'],
                    'attempts' => $entry['attempts'],
                    'token' => $entry['token'],
                    'last_try' => is_int($entry['last_try'] ?? null) ? $entry['last_try'] : $entry['at'],
                ];
            }
        }

        return $kept;
    }

    /** @return list<string> */
    private static function idents(array $batch): array
    {
        $idents = [];
        foreach ($batch as $event) {
            $ident = $event['data']['identification_number'] ?? null;
            if (is_string($ident) && $ident !== '') {
                $idents[] = $ident;
            }
        }

        return $idents;
    }

    private function refusedForGood(): bool
    {
        if (!method_exists($this->webhookSender, 'getLastStatusCode')) {
            return false;
        }

        return in_array($this->webhookSender->getLastStatusCode(), self::REFUSED_FOR_GOOD, true);
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
