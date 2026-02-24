<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before an entity is synced to Emporiqa.
 *
 * Listeners can call cancel() to prevent the sync for this entity.
 * Equivalent to Drupal's hook_emporiqa_entity_sync_alter()
 * and WooCommerce's emporiqa_should_sync_product/page filters.
 */
class PreSyncEvent extends Event
{
    public const NAME = 'emporiqa.pre_sync';

    private bool $cancelled = false;

    public function __construct(
        private object $entity,
        private string $entityType,
        private string $operation,
    ) {}

    public function getEntity(): object
    {
        return $this->entity;
    }

    /** Returns 'product', 'variation', or 'page'. */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /** Returns 'create', 'update', or 'delete'. */
    public function getOperation(): string
    {
        return $this->operation;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
