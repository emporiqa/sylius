<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Queues webhook events during an HTTP request and flushes them
 * on kernel.terminate, after the response has been sent to the client.
 *
 * Deduplicates events by identification_number so that multiple variant
 * updates in one request only produce a single parent sync.
 * Preserves 'created' type over 'updated' when deduplicating.
 *
 * Console commands normally bypass the queue (they call sendBatch directly
 * for synchronous feedback), but if any code path queues events during a
 * console run we still flush on console.terminate as a safety net.
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
        return [
            KernelEvents::TERMINATE => ['flush', -100],
            ConsoleEvents::TERMINATE => ['flushOnConsoleTerminate', -100],
        ];
    }

    public function queue(array $events): void
    {
        foreach ($events as $event) {
            $key = $event['data']['identification_number'] ?? '';

            if (!isset($this->firstTypes[$key])) {
                $this->firstTypes[$key] = $event['type'];
            }

            // Delete always wins — never overwrite a delete with update/create
            $existingType = ($this->pendingEvents[$key]['type'] ?? null);
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

    public function hasPending(): bool
    {
        return !empty($this->pendingEvents);
    }
}
