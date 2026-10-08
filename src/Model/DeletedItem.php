<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Model;

/**
 * A synced product, variation or page that no longer exists in the shop,
 * as PreSyncEvent::getEntity() returns it when a deletion Emporiqa did not
 * accept earlier is sent again (the entity itself is gone by then).
 */
final class DeletedItem
{
    public function __construct(
        private string $entityType,
        private int $id,
    ) {}

    /** 'product', 'variation' or 'page'. */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /** As synced: product-{id}, variation-{id} or page-{id}. */
    public function getIdentificationNumber(): string
    {
        return $this->entityType . '-' . $this->id;
    }
}
