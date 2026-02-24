<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when an order tracking request is processed.
 *
 * Listeners can modify or replace the order data returned to the widget.
 * Equivalent to Drupal's hook_emporiqa_order_tracking_alter()
 * and WooCommerce's emporiqa_order_tracking_data filter.
 */
class OrderTrackingEvent extends Event
{
    public const NAME = 'emporiqa.order_tracking';

    public function __construct(
        private ?array $orderData,
        private string $orderIdentifier,
        private array $requestPayload,
    ) {}

    public function getOrderData(): ?array
    {
        return $this->orderData;
    }

    public function setOrderData(?array $orderData): void
    {
        $this->orderData = $orderData;
    }

    public function getOrderIdentifier(): string
    {
        return $this->orderIdentifier;
    }

    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }
}
