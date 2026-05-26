<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after the bundle computes a min-order-quantity for a product or
 * variant on a given channel. Listeners may override the value before it's
 * written into the webhook payload — useful for integrations that source the
 * constraint from somewhere else (per-customer rules, B2B catalogues, etc.).
 *
 * The default value is read from the configured product attribute
 * (`emporiqa.min_order_quantity_attribute`, default `min_order_qty`).
 */
class MinOrderQuantityEvent extends Event
{
    public const NAME = 'emporiqa.min_order_quantity';

    public function __construct(
        private int $quantity,
        private object $entity,
        private string $channelKey,
    ) {}

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = max(1, $quantity);
    }

    /**
     * Either a Sylius\Component\Core\Model\ProductInterface (for parent /
     * simple events) or a ProductVariantInterface (for variant events).
     */
    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getChannelKey(): string
    {
        return $this->channelKey;
    }
}
