<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventSubscriber;

use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\EventSubscriber\ProductEventSubscriber;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductEventSubscriberTest extends TestCase
{
    private WebhookEventQueue $webhookQueue;
    private ProductFormatterInterface $formatter;
    private LoggerInterface $logger;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->webhookQueue = new WebhookEventQueue($webhookSender);
        $this->formatter = $this->createMock(ProductFormatterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            fn ($event) => $event
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ProductEventSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('sylius.product.post_create', $events);
        $this->assertArrayHasKey('sylius.product.post_update', $events);
        $this->assertArrayHasKey('sylius.product.pre_delete', $events);
        $this->assertArrayHasKey('sylius.product_variant.post_create', $events);
        $this->assertArrayHasKey('sylius.product_variant.post_update', $events);
        $this->assertArrayHasKey('sylius.product_variant.pre_delete', $events);
        $this->assertArrayHasKey('sylius.product.post_delete', $events);
        $this->assertArrayHasKey('sylius.product_variant.post_delete', $events);
    }

    public function testOnProductCreateQueuesEventsWithCreatedType(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnProductCreateSkipsWhenSyncDisabled(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, false, $this->logger);
        $subscriber->onProductCreate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testOnProductUpdateQueuesEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductUpdate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnProductDeleteQueuesDeleteEventsOnceTheDeleteSucceeded(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('formatForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductDelete($event);
        $this->assertFalse($this->webhookQueue->hasPending());

        $subscriber->onProductPostDelete($event);
        $this->assertTrue($this->webhookQueue->hasPending());
    }

    /**
     * Sylius dispatches post_delete only when the delete went through; a
     * product used in an order cannot be deleted and must stay in Emporiqa.
     */
    public function testAProductDeleteThatFailedSendsNothing(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10]);
        $this->formatter->method('formatForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductDelete(new ResourceControllerEvent($product));
        $queue->flush();

        $this->assertSame([], $sent->batches);
    }

    public function testAVariantDeleteThatFailedSendsNothing(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10, 11, 12]);
        $this->formatter->method('formatVariantForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'variation-11']],
        ]);

        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onVariantDelete(new ResourceControllerEvent($product->getVariants()->get(1)));
        $queue->flush();

        $this->assertSame([], $sent->batches);
    }

    /**
     * The parent aggregates its variants, so a deleted variant re-sends the
     * product, built without it (Doctrine leaves it, id nulled, in the
     * loaded collection).
     */
    public function testAVariantDeleteSendsItsDeletionAndTheProductAgain(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10, 11, 12]);
        $deleted = $product->getVariants()->get(1);
        $subscriber = $this->subscriberWithRealFormatting($queue);

        $subscriber->onVariantDelete(new ResourceControllerEvent($deleted));
        $this->setId($deleted, null);
        $subscriber->onVariantPostDelete(new ResourceControllerEvent($deleted));
        $queue->flush();

        $this->assertSame([
            'product.deleted:variation-11',
            'product.updated:product-1',
            'product.updated:variation-10',
            'product.updated:variation-12',
        ], $sent->idents());
    }

    /**
     * A product left with one variant is sent as a simple product; the
     * variation row its last variant had would otherwise stay as an orphan.
     */
    public function testAVariantDeleteDownToOneVariantRemovesTheLastVariationRow(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10, 11]);
        $deleted = $product->getVariants()->get(1);
        $subscriber = $this->subscriberWithRealFormatting($queue);

        $subscriber->onVariantDelete(new ResourceControllerEvent($deleted));
        $this->setId($deleted, null);
        $subscriber->onVariantPostDelete(new ResourceControllerEvent($deleted));
        $queue->flush();

        $this->assertSame([
            'product.deleted:variation-11',
            'product.deleted:variation-10',
            'product.updated:product-1',
        ], $sent->idents());
    }

    /**
     * The admin's "new variant" form builds the variant with
     * ProductVariantFactory::createForProduct, which only calls setProduct();
     * a product whose variants were loaded before the flush does not list it.
     */
    public function testAVariantCreatedInTheAdminIsInTheProductPayload(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10]);
        $product->getVariants()->toArray();
        $created = new ProductVariant();
        $this->setId($created, 11);
        $created->setProduct($product);
        $this->assertFalse($product->hasVariant($created));
        $subscriber = $this->subscriberWithRealFormatting($queue);

        $subscriber->onVariantCreate(new ResourceControllerEvent($created));
        $queue->flush();

        $this->assertSame([
            'product.updated:product-1',
            'product.updated:variation-10',
            'product.updated:variation-11',
        ], $sent->idents());
    }

    public function testACreatedDisabledProductIsSentAsDeletedNotCreated(): void
    {
        [$sender, $sent] = $this->recordingSender();
        $queue = new WebhookEventQueue($sender);
        $product = $this->product(1, [10]);
        $this->formatter->method('format')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate(new ResourceControllerEvent($product));
        $queue->flush();

        $this->assertSame(['product.deleted:product-1', 'product.deleted:variation-10'], $sent->idents());
    }

    public function testLogsErrorOnFormatterFailure(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willThrowException(new \RuntimeException('Format failed'));

        $this->logger->expects($this->once())->method('error');

        $queue = new WebhookEventQueue($this->createMock(WebhookSenderInterface::class), $this->logger);
        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate($event);
        // The payload is built at flush, after the response.
        $queue->flush();
    }

    public function testIgnoresNonProductSubjects(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn(new \stdClass());

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger);
        $subscriber->onProductCreate($event);
    }

    public function testPreSyncEventCanCancelSync(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $cancellingDispatcher = $this->createMock(EventDispatcherInterface::class);
        $cancellingDispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof PreSyncEvent) {
                    $event->cancel();
                }
                return $event;
            }
        );

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $cancellingDispatcher);
        $subscriber->onProductCreate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testOnVariantCreateQueuesEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getProduct')->willReturn($product);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $this->formatter->method('format')->with($product)->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onVariantCreate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnVariantUpdateQueuesEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getProduct')->willReturn($product);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $this->formatter->method('format')->with($product)->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onVariantUpdate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnVariantCreateSkipsWhenSyncDisabled(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, false, $this->logger);
        $subscriber->onVariantCreate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testOnVariantCreateIgnoresNonVariantSubject(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn(new \stdClass());

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger);
        $subscriber->onVariantCreate($event);
    }

    public function testOnVariantCreateIgnoresVariantWithNullProduct(): void
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn(null);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger);
        $subscriber->onVariantCreate($event);
    }

    public function testOnVariantCreateLogsErrorOnFormatterFailure(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getProduct')->willReturn($product);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $this->formatter->method('format')->willThrowException(new \RuntimeException('Format failed'));
        $this->logger->expects($this->once())->method('error');

        $queue = new WebhookEventQueue($this->createMock(WebhookSenderInterface::class), $this->logger);
        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onVariantCreate($event);
        $queue->flush();
    }

    public function testPreSyncEventCanCancelVariantSync(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getProduct')->willReturn($product);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $cancellingDispatcher = $this->createMock(EventDispatcherInterface::class);
        $cancellingDispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof PreSyncEvent) {
                    $event->cancel();
                }
                return $event;
            }
        );

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $cancellingDispatcher);
        $subscriber->onVariantUpdate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testOnProductCreateSetsCreatedType(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $queuedEvents = [];
        $webhookSender = $this->createMock(WebhookSenderInterface::class);
        $queue = new WebhookEventQueue($webhookSender);

        $subscriber = new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate($event);

        // Verify the queue has events (type is changed to product.created inside onProductCreate)
        $this->assertTrue($queue->hasPending());
    }

    /**
     * A formatter stub shaped like the real one: one event per variant id in
     * the product's collection, a parent when there are several.
     */
    private function subscriberWithRealFormatting(WebhookEventQueue $queue): ProductEventSubscriber
    {
        $this->formatter->method('format')->willReturnCallback(function (ProductInterface $product): array {
            $variants = $product->getVariants();
            if ($variants->count() < 2) {
                return [['type' => 'product.updated', 'data' => ['identification_number' => 'product-' . $product->getId()]]];
            }
            $events = [['type' => 'product.updated', 'data' => ['identification_number' => 'product-' . $product->getId()]]];
            foreach ($variants as $variant) {
                $events[] = ['type' => 'product.updated', 'data' => ['identification_number' => 'variation-' . $variant->getId()]];
            }

            return $events;
        });
        $this->formatter->method('formatVariantForDeletion')->willReturnCallback(
            fn (ProductVariantInterface $variant): array => [['type' => 'product.deleted', 'data' => ['identification_number' => 'variation-' . $variant->getId()]]],
        );

        return new ProductEventSubscriber($queue, $this->formatter, true, $this->logger, $this->eventDispatcher);
    }

    /** @param list<int> $variantIds */
    private function product(int $id, array $variantIds): Product
    {
        $product = new Product();
        $this->setId($product, $id);
        foreach ($variantIds as $variantId) {
            $variant = new ProductVariant();
            $this->setId($variant, $variantId);
            $product->addVariant($variant);
        }

        return $product;
    }

    private function setId(object $entity, ?int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    /** @return array{0: WebhookSenderInterface, 1: object} */
    private function recordingSender(): array
    {
        $sent = new class () {
            /** @var list<list<array>> */
            public array $batches = [];

            /** @return list<string> */
            public function idents(): array
            {
                $idents = [];
                foreach ($this->batches as $batch) {
                    foreach ($batch as $event) {
                        $idents[] = $event['type'] . ':' . $event['data']['identification_number'];
                    }
                }

                return $idents;
            }
        };
        $sender = $this->createMock(WebhookSenderInterface::class);
        $sender->method('sendBatch')->willReturnCallback(function (array $events) use ($sent): bool {
            $sent->batches[] = $events;

            return true;
        });

        return [$sender, $sent];
    }
}
