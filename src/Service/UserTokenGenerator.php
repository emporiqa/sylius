<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

/**
 * @deprecated since 1.11.0, unused by the plugin. Its token named the user
 *             by login identifier and was written into the widget URL; use
 *             SignatureHelper::customerToken(), served by the uncached
 *             CustomerTokenController.
 */
class UserTokenGenerator
{
    public static function generate(string $userId, string $webhookSecret): string
    {
        $payload = json_encode(['uid' => $userId, 'ts' => time()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $webhookSecret);

        return $encodedPayload . '.' . $signature;
    }
}
