<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\WebhookSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WebhookSenderTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createSender(string $secret = 'test-secret'): WebhookSender
    {
        return new WebhookSender(
            $this->httpClient,
            'https://example.com/webhook',
            'store-123',
            $secret,
            $this->logger,
        );
    }

    public function testSendBatchReturnsTrue(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://example.com/webhook/store-123/', $this->callback(function (array $options) {
                $this->assertSame('application/json', $options['headers']['Content-Type']);
                $this->assertArrayHasKey('X-Webhook-Signature', $options['headers']);
                return true;
            }))
            ->willReturn($response);

        $sender = $this->createSender();
        $result = $sender->sendBatch([['type' => 'test', 'data' => []]]);

        $this->assertTrue($result);
    }

    public function testSendBatchReturnsFalseOnError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('Server Error');

        $this->httpClient->method('request')->willReturn($response);

        $this->logger
            ->expects($this->once())
            ->method('error');

        $sender = $this->createSender();
        $result = $sender->sendBatch([['type' => 'test', 'data' => []]]);

        $this->assertFalse($result);
    }

    public function testSendBatchEmptyEventsReturnsTrue(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $sender = $this->createSender();
        $result = $sender->sendBatch([]);

        $this->assertTrue($result);
    }

    public function testSendBatchIncludesSignatureWhenSecretSet(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $this->assertArrayHasKey('X-Webhook-Signature', $options['headers']);
                $payload = $options['body'];
                $expectedSignature = hash_hmac('sha256', $payload, 'my-secret');
                $this->assertSame($expectedSignature, $options['headers']['X-Webhook-Signature']);
                return true;
            }))
            ->willReturn($response);

        $sender = $this->createSender('my-secret');
        $sender->sendBatch([['type' => 'test', 'data' => []]]);
    }

    public function testSendDelegatesToSendBatch(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $decoded = json_decode($options['body'], true);
                $this->assertCount(1, $decoded['events']);
                $this->assertSame('product.updated', $decoded['events'][0]['type']);
                return true;
            }))
            ->willReturn($response);

        $sender = $this->createSender();
        $sender->send('product.updated', ['id' => 1]);
    }

    public function testTestConnectionReturnsSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('OK');

        $this->httpClient->method('request')->willReturn($response);

        $sender = $this->createSender();
        $result = $sender->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame('https://example.com/webhook/store-123/', $result['url']);
    }
}
