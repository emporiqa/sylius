<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

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
 */
class WebhookEventQueue implements EventSubscriberInterface
{
    /** @var array<string, array{type: string, data: array}> */
    private array $pendingEvents = [];

    /** @var array<string, string> First event type per dedup key */
    private array $firstTypes = [];

    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private ?LoggerInterface $logger = null,
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

    public function queue(array $events): void
    {
        foreach ($events as $event) {
            $key = $event['data']['identification_number'] ?? '';

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

    public function flush(): void
    {
        if (empty($this->pendingEvents)) {
            return;
        }

        $events = array_values($this->pendingEvents);
        $this->pendingEvents = [];
        $this->firstTypes = [];

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $eventIds = array_map(
                fn (array $ev) => ($ev['type'] ?? '?') . ':' . ($ev['data']['identification_number'] ?? '?'),
                $events,
            );
            $this->logger?->error('Failed to flush webhook event queue', [
                'events_count' => count($events),
                'events' => $eventIds,
                'error' => $e->getMessage(),
            ]);
        }
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
        $this->pendingEvents = [];
        $this->firstTypes = [];
    }

    public function hasPending(): bool
    {
        return !empty($this->pendingEvents);
    }

    /**
     * Returns true when an event for the given identification_number is
     * already queued. Used by the stock listener to suppress a lightweight
     * product.availability event when a full product update is already
     * pending for the same variant/product in this request.
     */
    public function hasPendingFor(string $identificationNumber): bool
    {
        return isset($this->pendingEvents[$identificationNumber]);
    }
}
