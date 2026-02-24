<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before webhook events are sent to Emporiqa.
 *
 * Listeners can modify or filter the events array before delivery.
 * Equivalent to Drupal's hook_emporiqa_data_alter()
 * and WooCommerce's emporiqa_product_data / emporiqa_page_data filters.
 */
class PreWebhookSendEvent extends Event
{
    public const NAME = 'emporiqa.pre_webhook_send';

    public function __construct(
        private array $events,
    ) {}

    /** @return array<array{type: string, data: array}> */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @param array<array{type: string, data: array}> $events */
    public function setEvents(array $events): void
    {
        $this->events = $events;
    }
}
