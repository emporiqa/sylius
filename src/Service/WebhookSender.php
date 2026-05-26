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
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_MS = 500;

    /**
     * Friendly message from the most recent failed sendBatch() call, or null
     * after a success. Used by user-triggered flows (Sync / Test Connection)
     * to surface a human-readable reason instead of a bare boolean false.
     * Mirrors the WooCommerce and PrestaShop clients.
     */
    private ?string $lastError = null;

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
            $this->lastError = null;
            return true;
        }

        if ($this->eventDispatcher) {
            $preEvent = new PreWebhookSendEvent($events);
            $this->eventDispatcher->dispatch($preEvent, PreWebhookSendEvent::NAME);
            $events = $preEvent->getEvents();
            if (empty($events)) {
                $this->lastError = null;
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
        $lastResult = null;
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
                    $this->lastError = null;
                    return true;
                }

                $body = $response->getContent(false);
                $decoded = json_decode($body, true);
                $lastResult = [
                    'status_code' => $statusCode,
                    'response' => is_array($decoded) ? $decoded : $body,
                    'error' => sprintf('HTTP %d', $statusCode),
                ];

                // Client errors (4xx) are not retryable
                if ($statusCode >= 400 && $statusCode < 500) {
                    $this->logger?->error('Emporiqa webhook failed (not retryable)', [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'response' => $body,
                    ]);
                    $this->lastError = $this->buildFriendlyError($lastResult);
                    return false;
                }

                $lastError = sprintf('HTTP %d: %s', $statusCode, $body);
            } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
                $lastError = $e->getMessage();
                $lastResult = [
                    'status_code' => null,
                    'response' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->logger?->error('Emporiqa webhook failed after retries', [
            'url' => $url,
            'attempts' => self::MAX_RETRIES + 1,
            'last_error' => $lastError,
        ]);

        $this->lastError = $lastResult !== null
            ? $this->buildFriendlyError($lastResult)
            : $lastError;

        return false;
    }

    public function sendDryRun(array $events): array
    {
        if (empty($events)) {
            return ['success' => false, 'error' => 'No events to send'];
        }

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR);
        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/?dry_run=true';

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

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $decoded = json_decode($body, true);

            return [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'url' => $url,
                'response' => $decoded ?? $body,
            ];
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => $url,
            ];
        }
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

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Turn a failure result into a single human-readable line by pulling the
     * most informative field out of the Django response (`error`, `detail`,
     * `message`, `errors[0]`, plus `hint` when set). Mirrors the WooCommerce
     * and PrestaShop clients so merchants see the same wording everywhere.
     *
     * @param array{status_code?: int|null, response?: mixed, error?: mixed} $result
     */
    public function buildFriendlyError(array $result): string
    {
        $body = isset($result['response']) && is_array($result['response']) ? $result['response'] : [];
        $parts = [];

        foreach (['error', 'detail', 'message'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                $parts[] = $body[$key];
                break;
            }
        }

        if (!empty($body['errors']) && is_array($body['errors'])) {
            $first = reset($body['errors']);
            $parts[] = is_string($first) ? $first : (json_encode($first) ?: 'unknown error');
        }

        if (!empty($body['hint']) && is_string($body['hint'])) {
            $parts[] = '(' . $body['hint'] . ')';
        }

        if (empty($parts)) {
            $fallback = $result['error'] ?? 'Unknown error';
            $parts[] = is_string($fallback) ? $fallback : (json_encode($fallback) ?: 'Unknown error');
        }

        return implode(' ', $parts);
    }
}
