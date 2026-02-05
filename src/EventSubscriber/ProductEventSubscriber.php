<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private ProductFormatterInterface $formatter,
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
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

        $events = $this->formatter->format($product);
        foreach ($events as &$webhookEvent) {
            $webhookEvent['type'] = 'product.created';
        }
        unset($webhookEvent);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send product create webhook', [
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

        $events = $this->formatter->format($product);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send product update webhook', [
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

        $events = $this->formatter->formatForDeletion($product);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send product delete webhook', [
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

        $events = $this->formatter->format($product);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send variant create webhook', [
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

        $events = $this->formatter->format($product);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send variant update webhook', [
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

        $events = $this->formatter->formatVariantForDeletion($variant, $product);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send variant delete webhook', [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
