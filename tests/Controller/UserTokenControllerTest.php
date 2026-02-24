<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Emporiqa\SyliusPlugin\Controller\UserTokenController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class UserTokenControllerTest extends TestCase
{
    private Security $security;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
    }

    private function createController(string $secret = 'test-secret'): UserTokenController
    {
        return new UserTokenController($secret, $this->security);
    }

    public function testReturnsNullTokenWhenNoSecret(): void
    {
        $controller = $this->createController('');

        $response = $controller->getToken();

        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['token']);
    }

    public function testReturnsNullTokenForAnonymousUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $controller = $this->createController();
        $response = $controller->getToken();

        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['token']);
    }

    public function testReturnsSignedTokenForAuthenticatedUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $controller = $this->createController('my-secret');
        $response = $controller->getToken();

        $data = json_decode($response->getContent(), true);
        $this->assertNotNull($data['token']);

        $parts = explode('.', $data['token']);
        $this->assertCount(2, $parts);

        $decodedPayload = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('user@example.com', $decodedPayload['uid']);
        $this->assertArrayHasKey('ts', $decodedPayload);

        $expectedSig = hash_hmac('sha256', $parts[0], 'my-secret');
        $this->assertSame($expectedSig, $parts[1]);
    }

    public function testResponseHasNoStoreCacheHeaders(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('test-user');
        $this->security->method('getUser')->willReturn($user);

        $controller = $this->createController();
        $response = $controller->getToken();

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function testGenerateUserTokenStaticMethod(): void
    {
        $token = UserTokenController::generateUserToken('42', 'secret');

        $parts = explode('.', $token);
        $this->assertCount(2, $parts);

        $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('42', $decoded['uid']);

        $expectedSig = hash_hmac('sha256', $parts[0], 'secret');
        $this->assertSame($expectedSig, $parts[1]);
    }
}
