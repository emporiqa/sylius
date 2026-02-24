<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

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
    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private ProductFormatterInterface $formatter,
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.product.post_create' => 'onProductCreate',
            'sylius.product.post_update' => 'onProductUpdate',
            'sylius.product.pre_delete' => 'onProductDelete',
            'sylius.product_variant.post_create' => 'onVariantCreate',
            'sylius.product_variant.post_update' => 'onVariantUpdate',
            'sylius.product_variant.pre_delete' => 'onVariantDelete',
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

        try {
            $events = $this->formatter->format($product);
            foreach ($events as &$webhookEvent) {
                $webhookEvent['type'] = 'product.created';
            }
            unset($webhookEvent);

            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue product create webhook', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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

        try {
            $events = $this->formatter->format($product);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue product update webhook', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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
            $events = $this->formatter->formatForDeletion($product);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue product delete webhook', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
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

        try {
            $events = $this->formatter->format($product);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant create webhook', [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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

        try {
            $events = $this->formatter->format($product);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant update webhook', [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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
            $events = $this->formatter->formatVariantForDeletion($variant, $product);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant delete webhook', [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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
}
