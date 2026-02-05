<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookSender implements WebhookSenderInterface
{
    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
        private string $storeId,
        private string $webhookSecret,
        private ?LoggerInterface $logger = null,
        private int $timeout = self::DEFAULT_TIMEOUT,
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

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR);
        $url = rtrim($this->webhookUrl, '/') . '/' . $this->storeId . '/';

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
            $success = $statusCode >= 200 && $statusCode < 300;

            if (!$success) {
                $this->logger?->error('Emporiqa webhook failed', [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response' => $response->getContent(false),
                ]);
            } else {
                $this->logger?->info('Emporiqa webhook sent successfully', [
                    'url' => $url,
                    'events_count' => count($events),
                ]);
            }

            return $success;
        } catch (TransportExceptionInterface $e) {
            $this->logger?->error('Emporiqa webhook transport error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (HttpExceptionInterface $e) {
            $this->logger?->error('Emporiqa webhook HTTP error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
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
                        'language' => 'en',
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
