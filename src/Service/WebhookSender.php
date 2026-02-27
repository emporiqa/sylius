<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Event\PreWebhookSendEvent;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookSender implements WebhookSenderInterface
{
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_MS = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
        private string $storeId,
        private string $webhookSecret,
        private ?LoggerInterface $logger = null,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function send(string $event, array $data): bool
    {
        return $this->sendBatch([['type' => $event, 'data' => $data]]);
    }

    public function sendBatch(array $events): bool
    {
        if (empty($events)) {
            return true;
        }

        if ($this->eventDispatcher) {
            $preEvent = new PreWebhookSendEvent($events);
            $this->eventDispatcher->dispatch($preEvent, PreWebhookSendEvent::NAME);
            $events = $preEvent->getEvents();
            if (empty($events)) {
                return true;
            }
        }

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR);
        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/';

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => hash_hmac('sha256', $payload, $this->webhookSecret),
        ];

        $lastError = '';
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                $this->logger?->info('Emporiqa webhook retry', ['attempt' => $attempt + 1, 'url' => $url]);
            }

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                    'timeout' => $this->timeout,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger?->info('Emporiqa webhook sent successfully', [
                        'url' => $url,
                        'events_count' => count($events),
                    ]);
                    return true;
                }

                // Client errors (4xx) are not retryable
                if ($statusCode >= 400 && $statusCode < 500) {
                    $this->logger?->error('Emporiqa webhook failed (not retryable)', [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'response' => $response->getContent(false),
                    ]);
                    return false;
                }

                $lastError = sprintf('HTTP %d: %s', $statusCode, $response->getContent(false));
            } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
                $lastError = $e->getMessage();
            }
        }

        $this->logger?->error('Emporiqa webhook failed after retries', [
            'url' => $url,
            'attempts' => self::MAX_RETRIES + 1,
            'last_error' => $lastError,
        ]);

        return false;
    }

    public function testConnection(): array
    {
        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/';
        $payload = json_encode([
            'events' => [
                [
                    'type' => 'sync.start',
                    'data' => [
                        'session_id' => 'connection-test-' . bin2hex(random_bytes(8)),
                        'entity' => 'products',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => hash_hmac('sha256', $payload, $this->webhookSecret),
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $payload,
                'timeout' => $this->timeout,
            ]);

            return [
                'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                'status_code' => $response->getStatusCode(),
                'response' => $response->getContent(false),
                'url' => $url,
            ];
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => $url,
            ];
        }
    }
}
