<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Event\PreWebhookSendEvent;
use Emporiqa\SyliusPlugin\Service\WebhookSender;
use Emporiqa\SyliusPlugin\EmporiqaPlugin;
use Emporiqa\SyliusPlugin\Service\SignatureHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
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

    public function testSendBatchRetries429AndSucceeds(): void
    {
        $rateLimited = $this->createMock(ResponseInterface::class);
        $rateLimited->method('getStatusCode')->willReturn(429);
        $rateLimited->method('getContent')->willReturn('{"error":"Too many requests"}');
        $rateLimited->method('getHeaders')->willReturn(['retry-after' => ['0']]);

        $ok = $this->createMock(ResponseInterface::class);
        $ok->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($rateLimited, $ok);

        $sender = $this->createSender();

        $this->assertTrue($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertNull($sender->getLastError());
    }

    public function testSendBatchReturnsFalseWhen429Persists(): void
    {
        $rateLimited = $this->createMock(ResponseInterface::class);
        $rateLimited->method('getStatusCode')->willReturn(429);
        $rateLimited->method('getContent')->willReturn('{"error":"Too many requests"}');
        $rateLimited->method('getHeaders')->willReturn(['retry-after' => ['0']]);

        $this->httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturn($rateLimited);

        $sender = $this->createSender();

        $this->assertFalse($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertSame('Too many requests', $sender->getLastError());
    }

    public function testSendBatchDoesNotRetryOther4xx(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getContent')->willReturn('{"error":"Bad payload"}');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $sender = $this->createSender();

        $this->assertFalse($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertSame('Bad payload', $sender->getLastError());
    }

    /** The queue keeps a failed batch unless Emporiqa refused its content. */
    public function testTheLastStatusCodeIsReported(): void
    {
        $refused = $this->createMock(ResponseInterface::class);
        $refused->method('getStatusCode')->willReturn(400);
        $refused->method('getContent')->willReturn('{"error":"Validation failed"}');
        $accepted = $this->createMock(ResponseInterface::class);
        $accepted->method('getStatusCode')->willReturn(202);
        $this->httpClient->method('request')->willReturnOnConsecutiveCalls(
            $refused,
            $accepted,
            $this->throwException(new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused')),
            $this->throwException(new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused')),
            $this->throwException(new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused')),
        );
        $sender = $this->createSender();

        $this->assertFalse($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertSame(400, $sender->getLastStatusCode());
        $this->assertTrue($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertSame(202, $sender->getLastStatusCode());
        $this->assertFalse($sender->sendBatch([['type' => 'test', 'data' => []]]));
        $this->assertNull($sender->getLastStatusCode());
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

    public function testSendDryRunAppendsQueryParam(): void
    {
        $dryRunResponse = json_encode([
            'status' => 'dry_run',
            'signature' => 'valid',
            'events_validated' => 1,
            'events' => [
                [
                    'type' => 'product.updated',
                    'valid' => true,
                    'identification_number' => 'product-1',
                    'sku' => 'TEST-001',
                    'languages_detected' => ['en'],
                    'channels_detected' => [''],
                    'fields' => ['names' => true, 'prices' => true],
                    'is_parent' => false,
                    'parent_sku' => null,
                    'warnings' => [],
                ],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($dryRunResponse);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://example.com/webhook/store-123/?dry_run=true', $this->anything())
            ->willReturn($response);

        $sender = $this->createSender();
        $result = $sender->sendDryRun([['type' => 'product.updated', 'data' => ['sku' => 'TEST']]]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
        $this->assertStringContainsString('dry_run=true', $result['url']);
        $this->assertSame('dry_run', $result['response']['status']);
        $this->assertTrue($result['response']['events'][0]['valid']);
    }

    public function testSendDryRunReturnsErrorOn401(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);
        $response->method('getContent')->willReturn('{"error":"Invalid signature"}');

        $this->httpClient->method('request')->willReturn($response);

        $sender = $this->createSender();
        $result = $sender->sendDryRun([['type' => 'product.updated', 'data' => []]]);

        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['status_code']);
    }

    public function testSendDryRunReturnsErrorOn400(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getContent')->willReturn('{"error":"Invalid payload"}');

        $this->httpClient->method('request')->willReturn($response);

        $sender = $this->createSender();
        $result = $sender->sendDryRun([['type' => 'product.updated', 'data' => []]]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status_code']);
    }

    public function testSendDryRunHandlesNetworkFailure(): void
    {
        $this->httpClient->method('request')->willThrowException(
            new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused')
        );

        $sender = $this->createSender();
        $result = $sender->sendDryRun([['type' => 'product.updated', 'data' => []]]);

        $this->assertFalse($result['success']);
        $this->assertSame('Connection refused', $result['error']);
    }

    public function testSendDryRunRejectsEmptyEvents(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $sender = $this->createSender();
        $result = $sender->sendDryRun([]);

        $this->assertFalse($result['success']);
    }

    public function testSendDryRunIncludesCorrectSignature(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{"status":"dry_run"}');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $payload = $options['body'];
                $expectedSignature = hash_hmac('sha256', $payload, 'dry-run-secret');
                $this->assertSame($expectedSignature, $options['headers']['X-Webhook-Signature']);
                return true;
            }))
            ->willReturn($response);

        $sender = $this->createSender('dry-run-secret');
        $sender->sendDryRun([['type' => 'product.updated', 'data' => ['sku' => 'X']]]);
    }

    public function testPreWebhookSendEventCanFilterEvents(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof PreWebhookSendEvent) {
                    $event->setEvents([]);
                }
                return $event;
            }
        );

        $this->httpClient->expects($this->never())->method('request');

        $sender = new WebhookSender(
            $this->httpClient,
            'https://example.com/webhook',
            'store-123',
            'test-secret',
            $this->logger,
            30,
            $dispatcher,
        );

        $result = $sender->sendBatch([['type' => 'test', 'data' => []]]);
        $this->assertTrue($result);
    }

    public function testPreWebhookSendEventCanModifyEvents(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof PreWebhookSendEvent) {
                    $event->setEvents([['type' => 'modified', 'data' => ['changed' => true]]]);
                }
                return $event;
            }
        );

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) {
                $decoded = json_decode($options['body'], true);
                $this->assertSame('modified', $decoded['events'][0]['type']);
                return true;
            }))
            ->willReturn($response);

        $sender = new WebhookSender(
            $this->httpClient,
            'https://example.com/webhook',
            'store-123',
            'test-secret',
            $this->logger,
            30,
            $dispatcher,
        );

        $sender->sendBatch([['type' => 'original', 'data' => []]]);
    }

    public function testSendBatchCarriesBothSignaturesAndThePluginVersion(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $captured = null;
        $this->httpClient->method('request')->willReturnCallback(function ($method, $url, array $options) use (&$captured, $response) {
            $captured = $options;
            return $response;
        });

        $this->createSender('test-secret')->sendBatch([['type' => 'test', 'data' => []]]);

        $body = $captured['body'];
        $headers = $captured['headers'];
        $this->assertSame(hash_hmac('sha256', $body, 'test-secret'), $headers['X-Webhook-Signature']);
        $this->assertSame('ok', SignatureHelper::verifyHeader(
            $headers['X-Emporiqa-Webhook-Signature'],
            $body,
            'test-secret',
            'store-123',
            SignatureHelper::LABEL_INBOUND,
        ));
        $this->assertSame('sylius/' . EmporiqaPlugin::VERSION, $headers['X-Emporiqa-Plugin-Version']);
        $this->assertMatchesRegularExpression('/^sylius\/\d+(\.\d+){0,3}$/', $headers['X-Emporiqa-Plugin-Version']);
    }

    public function testEveryRetryIsSignedAgain(): void
    {
        $fail = $this->createMock(ResponseInterface::class);
        $fail->method('getStatusCode')->willReturn(503);
        $fail->method('getContent')->willReturn('');
        $headers = [];
        $this->httpClient->method('request')->willReturnCallback(function ($method, $url, array $options) use (&$headers, $fail) {
            $headers[] = $options['headers']['X-Emporiqa-Webhook-Signature'];
            return $fail;
        });

        $this->createSender()->sendBatch([['type' => 'test', 'data' => []]]);

        // Built per attempt (a fresh t once a second has passed), never reused from a stale array.
        $this->assertCount(3, $headers);
        foreach ($headers as $header) {
            $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
        }
    }

    public function testDryRunReportsClockSkewFromTheDateHeader(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{"status":"dry_run"}');
        $response->method('getHeaders')->willReturn(['date' => [gmdate('D, d M Y H:i:s', time() - 200) . ' GMT']]);
        $this->httpClient->method('request')->willReturn($response);

        $result = $this->createSender()->sendDryRun([['type' => 'test', 'data' => []]]);

        $this->assertEqualsWithDelta(200, $result['clock_skew'], 2);
    }

    public function testAPlainHttpUrlIsNeverSentTo(): void
    {
        $this->httpClient->expects($this->never())->method('request');
        $this->logger->expects($this->atLeastOnce())->method('error');

        $sender = new WebhookSender($this->httpClient, 'http://emporiqa.com/webhooks/sync/', 'store-123', 'test-secret', $this->logger);

        $this->assertFalse($sender->sendBatch([['type' => 'order.completed', 'data' => ['order_number' => '000000042']]]));
        $this->assertStringContainsString('https://', (string) $sender->getLastError());
        $this->assertFalse($sender->testConnection()['success']);
        $this->assertFalse($sender->sendDryRun([['type' => 'test', 'data' => []]])['success']);
    }

    public function testOnlyHttpsWithAHostIsSecure(): void
    {
        $this->assertTrue(WebhookSender::isSecureUrl('https://emporiqa.com/webhooks/sync/'));
        $this->assertTrue(WebhookSender::isSecureUrl('HTTPS://test.emporiqa.com/webhooks/sync/'));
        $this->assertFalse(WebhookSender::isSecureUrl('http://emporiqa.com/webhooks/sync/'));
        $this->assertFalse(WebhookSender::isSecureUrl('http://localhost:8000/webhooks/sync/'));
        $this->assertFalse(WebhookSender::isSecureUrl('emporiqa.com/webhooks/sync/'));
        $this->assertFalse(WebhookSender::isSecureUrl('https:///webhooks/sync/'));
    }
}
