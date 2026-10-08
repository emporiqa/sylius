<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

use Emporiqa\SyliusPlugin\Event\PostFormatEvent;
use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductEventSubscriber implements EventSubscriberInterface
{
    /**
     * Delete events built on pre_delete, while the ids still exist, and sent
     * on post_delete, which Sylius dispatches only when the delete succeeded
     * (a product or variant used in an order cannot be deleted).
     *
     * @var \WeakMap<object, array>
     */
    private \WeakMap $pendingDeletes;

    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private ProductFormatterInterface $formatter,
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->pendingDeletes = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.product.post_create' => 'onProductCreate',
            'sylius.product.post_update' => 'onProductUpdate',
            'sylius.product.pre_delete' => 'onProductDelete',
            'sylius.product.post_delete' => 'onProductPostDelete',
            'sylius.product_variant.post_create' => 'onVariantCreate',
            'sylius.product_variant.post_update' => 'onVariantUpdate',
            'sylius.product_variant.pre_delete' => 'onVariantDelete',
            'sylius.product_variant.post_delete' => 'onVariantPostDelete',
        ];
    }

    public function onProductCreate(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $product = $event->getSubject();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($product, 'product', 'create')) {
            return;
        }

        $this->queueProduct($product, true);
    }

    public function onProductUpdate(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $product = $event->getSubject();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($product, 'product', 'update')) {
            return;
        }

        $this->queueProduct($product);
    }

    public function onProductDelete(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $product = $event->getSubject();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($product, 'product', 'delete')) {
            return;
        }

        try {
            $this->pendingDeletes[$product] = $this->formatter->formatForDeletion($product);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue product delete webhook', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onProductPostDelete(ResourceControllerEvent $event): void
    {
        $product = $event->getSubject();
        if (!$product instanceof ProductInterface) {
            return;
        }

        $events = $this->takePendingDeletes($product);
        if ($events !== []) {
            $this->webhookQueue->queue($events);
        }
    }

    public function onVariantCreate(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $variant = $event->getSubject();
        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($variant, 'variation', 'create')) {
            return;
        }

        // The admin form's factory only sets the variant's product, so a
        // product whose variants were loaded before the flush does not list
        // it, and the payload built from it would leave the new variant out.
        if (!$product->hasVariant($variant)) {
            $product->addVariant($variant);
        }

        $this->queueProduct($product);
    }

    public function onVariantUpdate(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $variant = $event->getSubject();
        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($variant, 'variation', 'update')) {
            return;
        }

        $this->queueProduct($product);
    }

    public function onVariantDelete(ResourceControllerEvent $event): void
    {
        if (!$this->syncEnabled) {
            return;
        }

        $variant = $event->getSubject();
        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return;
        }

        if ($this->isSyncCancelled($variant, 'variation', 'delete')) {
            return;
        }

        try {
            $this->pendingDeletes[$variant] = $this->formatter->formatVariantForDeletion($variant, $product);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant delete webhook', [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The variant is gone: send its deletion and the product again, whose
     * parent aggregates (availability, price, options) included it. A product
     * left with one variant is sent as a simple product from now on, so the
     * variation row that variant had is deleted too.
     */
    public function onVariantPostDelete(ResourceControllerEvent $event): void
    {
        $variant = $event->getSubject();
        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        $events = $this->takePendingDeletes($variant);
        $product = $variant->getProduct();
        if ($events === [] || !$product instanceof ProductInterface) {
            return;
        }

        // Doctrine leaves the deleted variant (its id now null) in the
        // loaded collection; the payload is built from that collection.
        $product->removeVariant($variant);

        try {
            $remaining = $product->getVariants()->first();
            if ($product->getVariants()->count() === 1 && $remaining instanceof ProductVariantInterface) {
                array_push($events, ...$this->formatter->formatVariantForDeletion($remaining, $product));
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant delete webhook', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->webhookQueue->queue($events);
        $this->queueProduct($product);
    }

    /**
     * A product whose channel price changed outside a resource form: a
     * catalog promotion starting, ending or being reapplied (Sylius writes
     * the prices from a Messenger handler, so no resource event fires).
     */
    public function onChannelPriceChanged(ProductInterface $product): void
    {
        if ($this->syncEnabled && !$this->isSyncCancelled($product, 'product', 'update')) {
            $this->queueProduct($product);
        }
    }

    /**
     * The payload is built when the queue flushes, after the response, once
     * per product however many of its variants were saved in the request.
     */
    private function queueProduct(ProductInterface $product, bool $created = false): void
    {
        $this->webhookQueue->queueBuild(
            'product-' . $product->getId(),
            function (bool $created) use ($product): array {
                $events = $this->formatter->format($product);
                if ($created) {
                    foreach ($events as &$webhookEvent) {
                        if ($webhookEvent['type'] === 'product.updated') {
                            $webhookEvent['type'] = 'product.created';
                        }
                    }
                    unset($webhookEvent);
                }

                return $this->dispatchPostFormat($events, $product);
            },
            $created,
        );
    }

    private function takePendingDeletes(object $entity): array
    {
        $events = $this->pendingDeletes[$entity] ?? [];
        unset($this->pendingDeletes[$entity]);

        return $events;
    }

    private function isSyncCancelled(object $entity, string $entityType, string $operation): bool
    {
        if (!$this->eventDispatcher) {
            return false;
        }

        $event = new PreSyncEvent($entity, $entityType, $operation);
        $this->eventDispatcher->dispatch($event, PreSyncEvent::NAME);

        return $event->isCancelled();
    }

    private function dispatchPostFormat(array $events, object $entity): array
    {
        if (!$this->eventDispatcher) {
            return $events;
        }

        $event = new PostFormatEvent($events, $entity);
        $this->eventDispatcher->dispatch($event, PostFormatEvent::NAME);

        return $event->getFormattedEvents();
    }
}
