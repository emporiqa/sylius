<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Service\VariantStockFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Emits the lightweight `product.availability` event when ONLY a variant's
 * inventory fields change at the ORM level — the high-frequency case being
 * order-driven stock decrements, which never touch product content and so
 * should not trigger a full product rebuild.
 *
 * Inventory-only detection: the Doctrine changeset for the variant must
 * contain at least one of the inventory fields (onHand, onHold, tracked) and
 * nothing else. Any non-inventory field in the changeset (price overrides on
 * the variant entity, code, position, …) means content changed, so we defer
 * to the full ProductEventSubscriber and skip the lightweight event.
 *
 * Mutual exclusivity with the full update path:
 *   - The full product update is driven by ProductEventSubscriber, which
 *     listens on the Sylius ResourceControllerEvent (admin form saves).
 *   - This listener fires on raw ORM updates. For the order-driven case only
 *     this listener fires (the win we want).
 *   - For a pure-stock admin edit both could fire; we additionally check the
 *     WebhookEventQueue: if a full event is already pending for this variant's
 *     identification_number (or its parent product's), we skip the lightweight
 *     event so the full update wins and we never double-emit.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
class VariantStockDoctrineListener
{
    private const INVENTORY_FIELDS = ['onHand', 'onHold', 'tracked'];

    /**
     * Audit/timestamp fields written automatically by Gedmo extensions
     * (Timestampable, Blameable) on every update. These carry no content and
     * must be ignored when deciding whether a change is inventory-only —
     * otherwise the order-driven decrement changeset
     * {onHand, updatedAt} would never be detected.
     */
    private const NEUTRAL_AUDIT_FIELDS = ['updatedAt', 'createdAt', 'createdBy', 'updatedBy'];

    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private VariantStockFormatterInterface $formatter,
        private bool $syncEnabled = true,
        private bool $stockSyncEnabled = true,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        if (!$this->syncEnabled || !$this->stockSyncEnabled) {
            return;
        }

        $entity = $args->getObject();
        if (!$entity instanceof ProductVariantInterface) {
            return;
        }

        $product = $entity->getProduct();
        if (!$product instanceof ProductInterface) {
            return;
        }

        $objectManager = $args->getObjectManager();
        if (!$objectManager instanceof EntityManagerInterface) {
            return;
        }

        $changeSet = $objectManager->getUnitOfWork()->getEntityChangeSet($entity);

        if (!$this->isInventoryOnlyChange($changeSet)) {
            return;
        }

        if ($this->isSyncCancelled($entity)) {
            return;
        }

        try {
            $events = $this->formatter->format($entity);
            if (empty($events)) {
                return;
            }

            // Mutual exclusivity: a full update already queued for this variant
            // or its parent product this request is a superset of these
            // lightweight availability events (the variation event and, for a
            // multi-variant product, the re-aggregated parent event) — skip
            // them so we never overwrite richer data with a thinner payload.
            $variantId = $events[0]['data']['identification_number'] ?? '';
            if (
                $this->webhookQueue->hasPendingFor($variantId)
                || $this->webhookQueue->hasPendingFor('product-' . $product->getId())
            ) {
                return;
            }

            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue variant availability webhook', [
                'variant_id' => $entity->getId(),
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when the changeset touches at least one inventory field and every
     * other changed field is a neutral audit/timestamp field. Sylius's
     * ProductVariant is Gedmo Timestampable, so a real order-driven decrement
     * produces a changeset like {onHand: [...], updatedAt: [...]} — the
     * updatedAt must be ignored. Any genuine content field (code, position,
     * a price override, …) means content changed, so this is not
     * inventory-only and we defer to the full product update path.
     *
     * An empty changeset is not inventory-only (nothing to send).
     *
     * @param array<string, mixed> $changeSet
     */
    private function isInventoryOnlyChange(array $changeSet): bool
    {
        if (empty($changeSet)) {
            return false;
        }

        $touchedInventory = false;

        foreach (array_keys($changeSet) as $field) {
            if (in_array($field, self::INVENTORY_FIELDS, true)) {
                $touchedInventory = true;
                continue;
            }

            if (in_array($field, self::NEUTRAL_AUDIT_FIELDS, true)) {
                continue;
            }

            // A genuine content field changed — defer to the full update.
            return false;
        }

        return $touchedInventory;
    }

    private function isSyncCancelled(ProductVariantInterface $variant): bool
    {
        if (!$this->eventDispatcher) {
            return false;
        }

        $event = new PreSyncEvent($variant, 'variation', 'update');
        $this->eventDispatcher->dispatch($event, PreSyncEvent::NAME);

        return $event->isCancelled();
    }
}
