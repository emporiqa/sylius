<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Emporiqa\SyliusPlugin\Controller\CustomerTokenController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The token only for a signed-in shop customer, by customer id (never the
 * login email), bound to this store with `aud`, and never cacheable.
 */
class CustomerTokenControllerTest extends TestCase
{
    private function controller(?object $user, string $secret = 'secret'): CustomerTokenController
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return new CustomerTokenController($secret, 'st_7Kq2mXa9', $security);
    }

    public function testSignedInCustomerGetsATokenForTheirCustomerId(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(77);
        $user = $this->createMock(ShopUserInterface::class);
        $user->method('getCustomer')->willReturn($customer);
        $user->method('getUserIdentifier')->willReturn('anna@example.com');

        $response = $this->controller($user)->token();

        $token = json_decode((string) $response->getContent(), true)['token'];
        [$encoded, $mac] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $this->assertSame(hash_hmac('sha256', $encoded, 'secret'), $mac);
        $this->assertSame('77', $claims['uid']);
        $this->assertSame('st_7Kq2mXa9', $claims['aud']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertContains('Cookie', $response->getVary());
    }

    public function testGuestGetsAnEmptyToken(): void
    {
        $response = $this->controller(null)->token();

        $this->assertSame(['token' => ''], json_decode((string) $response->getContent(), true));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testSignedInAdminIsNotACustomer(): void
    {
        $response = $this->controller($this->createMock(AdminUserInterface::class))->token();

        $this->assertSame(['token' => ''], json_decode((string) $response->getContent(), true));
    }
}
