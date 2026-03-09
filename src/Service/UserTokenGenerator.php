<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

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
