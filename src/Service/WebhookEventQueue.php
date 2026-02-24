<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Queues webhook events during an HTTP request and flushes them
 * on kernel.terminate, after the response has been sent to the client.
 *
 * Deduplicates events by (identification_number, language) so that
 * multiple variant updates in one request only produce a single parent sync.
 * Preserves 'created' type over 'updated' when deduplicating.
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
        ];
    }

    public function queue(array $events): void
    {
        foreach ($events as $event) {
            $identificationNumber = $event['data']['identification_number'] ?? '';
            $language = $event['data']['language'] ?? '';
            $key = $identificationNumber . ':' . $language;

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
            $this->logger?->error('Failed to flush webhook event queue', [
                'events_count' => count($events),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function hasPending(): bool
    {
        return !empty($this->pendingEvents);
    }
}
