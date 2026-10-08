<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\EmporiqaPlugin;
use Emporiqa\SyliusPlugin\Event\PreWebhookSendEvent;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WebhookSender implements WebhookSenderInterface
{
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_MS = 500;
    private const MAX_RETRY_AFTER_SECONDS = 10;

    /**
     * Friendly message from the most recent failed sendBatch() call, or null
     * after a success. Used by user-triggered flows (Sync / Test Connection)
     * to surface a human-readable reason instead of a bare boolean false.
     * Mirrors the WooCommerce and PrestaShop clients.
     */
    private ?string $lastError = null;

    /** HTTP status of the most recent sendBatch() answer; null without one. */
    private ?int $lastStatusCode = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
        private string $storeId,
        private string $webhookSecret,
        private ?LoggerInterface $logger = null,
        private int $timeout = self::DEFAULT_TIMEOUT,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    /**
     * Payloads carry order numbers, totals and the catalog, so they never go
     * out over plain http.
     */
    public static function isSecureUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    private function insecureUrlError(): ?string
    {
        if (self::isSecureUrl($this->webhookUrl)) {
            return null;
        }

        $error = 'The Emporiqa webhook_url must start with https://; nothing was sent.';
        $this->logger?->error($error, ['url' => $this->webhookUrl]);

        return $error;
    }

    public function send(string $event, array $data): bool
    {
        return $this->sendBatch([['type' => $event, 'data' => $data]]);
    }

    public function sendBatch(array $events): bool
    {
        $this->lastStatusCode = null;
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

        if ($error = $this->insecureUrlError()) {
            $this->lastError = $error;
            return false;
        }

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR);
        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/';

        $lastError = '';
        $lastResult = null;
        $retryDelayMs = null;
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(($retryDelayMs ?? self::RETRY_DELAY_MS * $attempt) * 1000);
                $this->logger?->info('Emporiqa webhook retry', ['attempt' => $attempt + 1, 'url' => $url]);
            }
            $retryDelayMs = null;

            try {
                // Signed per attempt: Emporiqa answers a scheme-2 signature it
                // already accepted with "duplicate", so a retry needs a fresh t.
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $this->buildHeaders($payload),
                    'body' => $payload,
                    'timeout' => $this->timeout,
                ]);

                $statusCode = $response->getStatusCode();
                $this->lastStatusCode = $statusCode;

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

                // Client errors (4xx) are not retryable — except 429, which
                // is transient rate limiting: it must be retried (honoring
                // Retry-After) so bursts during a large sync don't silently
                // drop batches that then get deleted at sync.complete.
                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    $this->logger?->error('Emporiqa webhook failed (not retryable)', [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'response' => $body,
                    ]);
                    $this->lastError = $this->buildFriendlyError($lastResult);
                    return false;
                }

                if ($statusCode === 429) {
                    $retryDelayMs = $this->retryAfterMs($response);
                }

                $lastError = sprintf('HTTP %d: %s', $statusCode, $body);
            } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
                $this->lastStatusCode = null;
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

        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/?dry_run=true';
        if ($error = $this->insecureUrlError()) {
            return ['success' => false, 'error' => $error, 'url' => $url];
        }

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR);

        $headers = $this->buildHeaders($payload);

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
                'clock_skew' => $this->clockSkew($response),
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
        if ($error = $this->insecureUrlError()) {
            return ['success' => false, 'error' => $error, 'url' => $url];
        }

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

        $headers = $this->buildHeaders($payload);

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

    /**
     * Both signature schemes while a platform that knows only the old header
     * may still receive this, plus the plugin version.
     *
     * @return array<string, string>
     */
    private function buildHeaders(string $payload): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => hash_hmac('sha256', $payload, $this->webhookSecret),
            'X-Emporiqa-Webhook-Signature' => SignatureHelper::buildHeader(
                SignatureHelper::deriveKey($this->webhookSecret, SignatureHelper::LABEL_INBOUND, $this->storeId),
                $payload,
            ),
            'X-Emporiqa-Plugin-Version' => 'sylius/' . EmporiqaPlugin::VERSION,
        ];
    }

    /**
     * Seconds this server's clock is off Emporiqa's, from the response Date
     * header; null when there is none. Emporiqa refuses signatures more than
     * 5 minutes off.
     */
    private function clockSkew(ResponseInterface $response): ?int
    {
        $date = $response->getHeaders(false)['date'][0] ?? null;
        $remote = is_string($date) ? strtotime($date) : false;

        return $remote === false ? null : time() - $remote;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    /**
     * Delay requested by a 429's Retry-After header, in milliseconds.
     * Handles both delta-seconds and HTTP-date forms, capped so a huge or
     * hostile value cannot stall the process. Returns null when the header
     * is absent or unparsable (caller falls back to the default backoff).
     */
    private function retryAfterMs(ResponseInterface $response): ?int
    {
        $value = $response->getHeaders(false)['retry-after'][0] ?? null;
        if ($value === null) {
            return null;
        }

        if (ctype_digit($value)) {
            $seconds = (int) $value;
        } else {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return null;
            }
            $seconds = $timestamp - time();
        }

        return max(0, min($seconds, self::MAX_RETRY_AFTER_SECONDS)) * 1000;
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
