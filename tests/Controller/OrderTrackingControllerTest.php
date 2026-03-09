<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Emporiqa\SyliusPlugin\Controller\OrderTrackingController;
use Emporiqa\SyliusPlugin\Event\OrderTrackingEvent;
use Emporiqa\SyliusPlugin\Service\OrderProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderTrackingControllerTest extends TestCase
{
    private const WEBHOOK_SECRET = 'test-secret-key';

    private OrderProviderInterface $orderProvider;
    private OrderTrackingController $controller;

    protected function setUp(): void
    {
        $this->orderProvider = $this->createMock(OrderProviderInterface::class);
        $this->controller = new OrderTrackingController(self::WEBHOOK_SECRET, $this->orderProvider);
    }

    private function createSignedRequest(array $payload): Request
    {
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $request = Request::create('/emporiqa/api/order/tracking', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function testRejectsInvalidSignature(): void
    {
        $body = json_encode(['order_identifier' => '123', 'timestamp' => time()]);
        $request = Request::create('/emporiqa/api/order/tracking', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', 'invalid-signature');

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid signature', $data['error']);
    }

    public function testRejectsMissingSignature(): void
    {
        $body = json_encode(['order_identifier' => '123', 'timestamp' => time()]);
        $request = Request::create('/emporiqa/api/order/tracking', 'POST', [], [], [], [], $body);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRejectsMissingOrderIdentifier(): void
    {
        $request = $this->createSignedRequest(['timestamp' => time()]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid request body', $data['error']);
    }

    public function testRejectsMissingTimestamp(): void
    {
        $request = $this->createSignedRequest(['order_identifier' => '123']);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturnsNotFoundWhenOrderProviderReturnsNull(): void
    {
        $this->orderProvider->method('findOrder')->willReturn(null);

        $request = $this->createSignedRequest([
            'order_identifier' => '000999',
            'timestamp' => time(),
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Order not found', $data['error']);
    }

    public function testReturnsOrderDataOnSuccess(): void
    {
        $orderData = [
            'order_id' => '000123',
            'status' => 'shipped',
            'placed_at' => '2026-01-15T10:30:00+00:00',
            'items' => [['name' => 'Test', 'quantity' => 1, 'price' => 19.99]],
            'shipping' => ['method' => 'DHL', 'tracking_number' => 'DHL123'],
            'total' => 19.99,
            'currency' => 'EUR',
        ];

        $this->orderProvider->method('findOrder')->willReturn($orderData);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time(),
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('000123', $data['order_id']);
        $this->assertSame('shipped', $data['status']);
        $this->assertSame(19.99, $data['total']);
    }

    public function testPassesVerificationFieldsToProvider(): void
    {
        $this->orderProvider
            ->expects($this->once())
            ->method('findOrder')
            ->with(
                '000123',
                'user-42',
                ['email' => 'customer@example.com'],
            )
            ->willReturn(null);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'user_id' => 'user-42',
            'verification_fields' => ['email' => 'customer@example.com'],
            'timestamp' => time(),
        ]);

        $this->controller->track($request);
    }

    public function testOrderTrackingEventCanModifyResponse(): void
    {
        $this->orderProvider->method('findOrder')->willReturn([
            'order_id' => '000123',
            'status' => 'shipped',
        ]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof OrderTrackingEvent) {
                    $data = $event->getOrderData();
                    $data['custom_field'] = 'added_by_listener';
                    $event->setOrderData($data);
                }
                return $event;
            }
        );

        $controller = new OrderTrackingController(self::WEBHOOK_SECRET, $this->orderProvider, $dispatcher);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time(),
        ]);

        $response = $controller->track($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('added_by_listener', $data['custom_field']);
    }

    public function testOrderTrackingEventCanNullifyResponse(): void
    {
        $this->orderProvider->method('findOrder')->willReturn([
            'order_id' => '000123',
            'status' => 'shipped',
        ]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof OrderTrackingEvent) {
                    $event->setOrderData(null);
                }
                return $event;
            }
        );

        $controller = new OrderTrackingController(self::WEBHOOK_SECRET, $this->orderProvider, $dispatcher);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time(),
        ]);

        $response = $controller->track($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time() - 301,
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Request expired', $data['error']);
    }

    public function testAcceptsTimestampAtExactBoundary(): void
    {
        $this->orderProvider->method('findOrder')->willReturn([
            'order_id' => '000123',
            'status' => 'new',
        ]);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time() - 300,
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRejectsFutureTimestampBeyondTolerance(): void
    {
        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time() + 301,
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAcceptsFutureTimestampWithinTolerance(): void
    {
        $this->orderProvider->method('findOrder')->willReturn([
            'order_id' => '000123',
            'status' => 'new',
        ]);

        $request = $this->createSignedRequest([
            'order_identifier' => '000123',
            'timestamp' => time() + 299,
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRejectsEmptyBody(): void
    {
        $body = '';
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $request = Request::create('/emporiqa/api/order/tracking', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Signature', $signature);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRejectsEmptyOrderIdentifier(): void
    {
        $request = $this->createSignedRequest([
            'order_identifier' => '',
            'timestamp' => time(),
        ]);

        $response = $this->controller->track($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
