<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a cart operation is executed.
 *
 * Listeners can cancel the operation by calling cancelOperation().
 * Available operations: 'add', 'update', 'remove', 'clear'.
 */
class CartOperationEvent extends Event
{
    public const NAME = 'emporiqa.cart_operation';

    private bool $cancelled = false;
    private string $cancelReason = '';

    public function __construct(
        private string $operation,
        private array $parameters,
    ) {}

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function cancelOperation(string $reason = ''): void
    {
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getCancelReason(): string
    {
        return $this->cancelReason;
    }
}
