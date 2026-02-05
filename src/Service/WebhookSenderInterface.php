<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

interface WebhookSenderInterface
{
    public function send(string $event, array $data): bool;

    public function sendBatch(array $events): bool;

    public function testConnection(): array;
}
