<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

class UserTokenController
{
    public function __construct(
        private string $webhookSecret,
        private Security $security,
    ) {}

    public function getToken(): JsonResponse
    {
        if (empty($this->webhookSecret)) {
            return new JsonResponse(['token' => null]);
        }

        $user = $this->security->getUser();
        if ($user === null) {
            return new JsonResponse(['token' => null]);
        }

        $token = self::generateUserToken(
            $user->getUserIdentifier(),
            $this->webhookSecret,
        );

        $response = new JsonResponse(['token' => $token]);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    public static function generateUserToken(string $userId, string $webhookSecret): string
    {
        $payload = json_encode(['uid' => $userId, 'ts' => time()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $webhookSecret);

        return $encodedPayload . '.' . $signature;
    }
}
