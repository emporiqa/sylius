<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

/**
 * Builds the webhook events that bring Emporiqa to the shop's current state
 * for some synced items, by identification number. Used to send again what
 * Emporiqa did not accept: the payload is built when it is sent, never kept,
 * so a retry can only ever send the newest state.
 */
interface CurrentStateBuilderInterface
{
    /**
     * @param list<string> $identificationNumbers product-{id}, variation-{id} or page-{id}
     * @param list<string> $failed set to the items whose state could not be built
     *
     * @return list<array{type: string, data: array}> the events to send; an
     *         item that no longer exists is answered with its deletion (unless
     *         a PreSyncEvent listener cancels it), one that cannot be read is
     *         left out and listed in $failed
     */
    public function build(array $identificationNumbers, array &$failed = []): array;
}
