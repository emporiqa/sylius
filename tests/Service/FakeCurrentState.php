<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\CurrentStateBuilderInterface;

/**
 * The "database" a retry is built from: identification number => version.
 * An ident that is missing is gone from the shop, so it is sent as deleted.
 */
class FakeCurrentState implements CurrentStateBuilderInterface
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, string> $state */
    public function __construct(public array $state) {}

    /** @var list<string> idents whose build throws */
    public array $broken = [];

    public int $calls = 0;

    public function build(array $identificationNumbers, array &$failed = []): array
    {
        ++$this->calls;
        $failed = array_values(array_intersect($identificationNumbers, $this->broken));
        $events = [];
        foreach ($identificationNumbers as $ident) {
            $this->asked[] = $ident;
            if (in_array($ident, $this->broken, true)) {
                continue;
            }
            $type = str_starts_with($ident, 'page-') ? 'page' : 'product';
            $events[] = isset($this->state[$ident])
                ? ['type' => $type . '.updated', 'data' => ['identification_number' => $ident, 'v' => $this->state[$ident]]]
                : ['type' => $type . '.deleted', 'data' => ['identification_number' => $ident]];
        }

        return $events;
    }
}
