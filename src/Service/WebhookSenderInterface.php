<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

interface WebhookSenderInterface
{
    public function send(string $event, array $data): bool;

    public function sendBatch(array $events): bool;

    public function testConnection(): array;

    public function sendDryRun(array $events): array;

    /**
     * Friendly message from the most recent failed sendBatch() call, or
     * null after a success. Callers (Sync / Test Connection commands) use
     * this to surface a human-readable reason instead of a bare false.
     */
    public function getLastError(): ?string;

    /**
     * Turn a failure result (sendDryRun / sendBatch internal) into a single
     * human-readable line by pulling the most informative field from the
     * Django response (`error`, `detail`, `message`, `errors[0]`, plus
     * `hint`). Available to callers that have a raw result array (e.g.
     * Test Connection uses sendDryRun which returns one directly).
     *
     * @param array{status_code?: int|null, response?: mixed, error?: mixed} $result
     */
    public function buildFriendlyError(array $result): string;
}
