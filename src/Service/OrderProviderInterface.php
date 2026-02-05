<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

interface OrderProviderInterface
{
    /**
     * Look up an order by its identifier and return formatted order data.
     *
     * @param string $identifier The order number or identifier
     * @param string|null $userId Optional user ID for authentication context
     * @param array<string, string> $verificationFields Fields to verify customer identity (e.g. email)
     * @return array<string, mixed>|null Formatted order data, or null if not found / verification fails
     */
    public function findOrder(string $identifier, ?string $userId, array $verificationFields): ?array;
}
