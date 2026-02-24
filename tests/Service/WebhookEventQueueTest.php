<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;

class WebhookEventQueueTest extends TestCase
{
    private WebhookSenderInterface $webhookSender;
    private WebhookEventQueue $queue;

    protected function setUp(): void
    {
        $this->webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->queue = new WebhookEventQueue($this->webhookSender);
    }

    public function testQueueAndFlush(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.updated', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);

        $this->assertTrue($this->queue->hasPending());
        $this->queue->flush();
        $this->assertFalse($this->queue->hasPending());
    }

    public function testFlushWithNoPendingEventsDoesNothing(): void
    {
        $this->webhookSender->expects($this->never())->method('sendBatch');

        $this->queue->flush();
    }

    public function testDeduplicatesBySameKey(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);

        $this->queue->flush();
    }

    public function testPreservesCreatedOverUpdated(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.created', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.created', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);

        $this->queue->flush();
    }

    public function testDeleteEventIsNeverOverwritten(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertSame('product.deleted', $events[0]['type']);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);

        $this->queue->flush();
    }

    public function testDifferentKeysAreNotDeduplicated(): void
    {
        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                $this->assertCount(2, $events);
                return true;
            }));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-2', 'language' => 'en']],
        ]);

        $this->queue->flush();
    }

    public function testFlushCatchesSenderExceptions(): void
    {
        $this->webhookSender->method('sendBatch')->willThrowException(new \RuntimeException('Connection failed'));

        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'language' => 'en']],
        ]);

        // Should not throw
        $this->queue->flush();
        $this->assertFalse($this->queue->hasPending());
    }
}
