<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a product or page is formatted for webhook delivery.
 *
 * Listeners can modify the formatted data before it is queued or sent.
 * The source entity is available for context.
 */
class PostFormatEvent extends Event
{
    public const NAME = 'emporiqa.post_format';

    public function __construct(
        private array $formattedEvents,
        private object $entity,
    ) {}

    /** @return array<array{type: string, data: array}> */
    public function getFormattedEvents(): array
    {
        return $this->formattedEvents;
    }

    /** @param array<array{type: string, data: array}> $formattedEvents */
    public function setFormattedEvents(array $formattedEvents): void
    {
        $this->formattedEvents = $formattedEvents;
    }

    public function getEntity(): object
    {
        return $this->entity;
    }
}
