<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\UserTokenGenerator;
use PHPUnit\Framework\TestCase;

class UserTokenGeneratorTest extends TestCase
{
    public function testGeneratesSignedToken(): void
    {
        $token = UserTokenGenerator::generate('user@example.com', 'my-secret');

        $parts = explode('.', $token);
        $this->assertCount(2, $parts);

        $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('user@example.com', $decoded['uid']);
        $this->assertArrayHasKey('ts', $decoded);
        $this->assertIsInt($decoded['ts']);

        $expectedSig = hash_hmac('sha256', $parts[0], 'my-secret');
        $this->assertSame($expectedSig, $parts[1]);
    }

    public function testSameUserGetsSameUid(): void
    {
        $token = UserTokenGenerator::generate('42', 'secret');
        $parts = explode('.', $token);
        $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('42', $decoded['uid']);
    }

    public function testDifferentUsersGetDifferentTokens(): void
    {
        $token1 = UserTokenGenerator::generate('user-a', 'secret');
        $token2 = UserTokenGenerator::generate('user-b', 'secret');

        $this->assertNotSame($token1, $token2);
    }
}
