<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\EventListener\VariantStockDoctrineListener;
use Emporiqa\SyliusPlugin\Service\VariantStockFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class VariantStockDoctrineListenerTest extends TestCase
{
    private WebhookEventQueue $queue;
    private VariantStockFormatterInterface $formatter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->queue = new WebhookEventQueue($this->createMock(WebhookSenderInterface::class));
        $this->formatter = $this->createMock(VariantStockFormatterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function makeArgs(ProductVariantInterface $variant, array $changeSet): PostUpdateEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->with($variant)->willReturn($changeSet);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostUpdateEventArgs($variant, $em);
    }

    private function makeVariant(): ProductVariantInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getProduct')->willReturn($product);

        return $variant;
    }

    public function testInventoryOnlyChangeQueuesAvailabilityEvent(): void
    {
        $variant = $this->makeVariant();

        $this->formatter->expects($this->once())->method('format')->with($variant)->willReturn([
            [
                'type' => 'product.availability',
                'data' => ['identification_number' => 'variation-10', 'sku' => 'SKU-1'],
            ],
        ]);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        $this->assertTrue($this->queue->hasPending());
        $this->assertTrue($this->queue->hasPendingFor('variation-10'));
    }

    public function testTrackedAndOnHoldOnlyStillCountsAsInventoryOnly(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->once())->method('format')->willReturn([
            [
                'type' => 'product.availability',
                'data' => ['identification_number' => 'variation-10'],
            ],
        ]);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHold' => [0, 2], 'tracked' => [false, true]]));

        $this->assertTrue($this->queue->hasPending());
    }

    public function testRealisticChangeSetWithUpdatedAtCountsAsInventoryOnly(): void
    {
        // Sylius ProductVariant is Gedmo Timestampable: every real
        // order-driven decrement writes updatedAt alongside onHand. This is
        // the production changeset and MUST be treated as inventory-only.
        $variant = $this->makeVariant();
        $this->formatter->expects($this->once())->method('format')->willReturn([
            [
                'type' => 'product.availability',
                'data' => ['identification_number' => 'variation-10'],
            ],
        ]);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, [
            'onHand' => [10, 7],
            'updatedAt' => [new \DateTime('-1 hour'), new \DateTime()],
        ]));

        $this->assertTrue($this->queue->hasPending());
        $this->assertTrue($this->queue->hasPendingFor('variation-10'));
    }

    public function testOnHandWithNameChangeIsNotInventoryOnly(): void
    {
        // A genuine content field (name) alongside onHand means content
        // changed → defer to the full product update, do not emit.
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, [
            'onHand' => [10, 7],
            'name' => ['Old name', 'New name'],
        ]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testOnlyAuditFieldsWithoutInventoryIsNotInventoryOnly(): void
    {
        // Only updatedAt changed, no inventory field → nothing to send.
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, [
            'updatedAt' => [new \DateTime('-1 hour'), new \DateTime()],
        ]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testNonInventoryFieldInChangeSetSkips(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        // onHand changed but so did code -> not inventory-only, full update handles it.
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7], 'code' => ['a', 'b']]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testEmptyChangeSetSkips(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, []));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testSkipsWhenStockSyncDisabled(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, false, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testSkipsWhenProductSyncDisabled(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, false, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testSkipsWhenFullVariantUpdateAlreadyQueued(): void
    {
        $variant = $this->makeVariant();

        // A full update for the same variant is already pending.
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $this->formatter->method('format')->willReturn([
            'type' => 'product.availability',
            'data' => ['identification_number' => 'variation-10'],
        ]);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        // Still exactly the one full event, no availability stacked on top.
        $this->assertTrue($this->queue->hasPendingFor('variation-10'));
        $this->assertFalse($this->queue->hasPendingFor('product-availability-marker'));
    }

    public function testSkipsWhenFullParentProductUpdateAlreadyQueued(): void
    {
        $variant = $this->makeVariant();

        // Full parent product update pending; lightweight event resolves to
        // variation-10 but its parent product-1 is already queued.
        $this->queue->queue([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->formatter->method('format')->willReturn([
            'type' => 'product.availability',
            'data' => ['identification_number' => 'variation-10'],
        ]);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        $this->assertFalse($this->queue->hasPendingFor('variation-10'));
    }

    public function testPreSyncEventCanCancel(): void
    {
        $variant = $this->makeVariant();
        $this->formatter->expects($this->never())->method('format');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof PreSyncEvent) {
                $event->cancel();
            }
            return $event;
        });

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger, $dispatcher);
        $listener->postUpdate($this->makeArgs($variant, ['onHand' => [10, 7]]));

        $this->assertFalse($this->queue->hasPending());
    }

    public function testIgnoresNonVariantEntities(): void
    {
        $this->formatter->expects($this->never())->method('format');

        $em = $this->createMock(EntityManagerInterface::class);
        $args = new PostUpdateEventArgs(new \stdClass(), $em);

        $listener = new VariantStockDoctrineListener($this->queue, $this->formatter, true, true, $this->logger);
        $listener->postUpdate($args);

        $this->assertFalse($this->queue->hasPending());
    }
}
