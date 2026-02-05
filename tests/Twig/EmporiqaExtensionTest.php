<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Twig;

use Emporiqa\SyliusPlugin\Twig\EmporiqaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

class EmporiqaExtensionTest extends TestCase
{
    private RequestStack $requestStack;
    private Security $security;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->security = $this->createMock(Security::class);
    }

    private function createExtension(
        string $storeId = 'test-store',
        string $webhookUrl = 'https://api.emporiqa.com/webhook',
        string $webhookSecret = 'test-secret',
        bool $cartEnabled = true,
    ): EmporiqaExtension {
        return new EmporiqaExtension(
            $storeId,
            $webhookUrl,
            $webhookSecret,
            $this->requestStack,
            $this->security,
            $cartEnabled,
        );
    }

    public function testGetFunctions(): void
    {
        $extension = $this->createExtension();
        $functions = $extension->getFunctions();

        $names = array_map(fn ($f) => $f->getName(), $functions);
        $this->assertContains('emporiqa_widget', $names);
        $this->assertContains('emporiqa_cart_widget', $names);
        $this->assertContains('emporiqa_store_id', $names);
        $this->assertContains('emporiqa_widget_url', $names);
    }

    public function testGetStoreId(): void
    {
        $extension = $this->createExtension('my-store-id');

        $this->assertSame('my-store-id', $extension->getStoreId());
    }

    public function testGetWidgetUrlWithDefaults(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook');
        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('api.emporiqa.com', $url);
        $this->assertStringContainsString('store_id=store-123', $url);
        $this->assertStringContainsString('language=en', $url);
        $this->assertStringNotContainsString('user_id', $url);
    }

    public function testGetWidgetUrlExtractsLocaleShortCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('de_DE');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension();
        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('language=de', $url);
    }

    public function testGetWidgetUrlIncludesUserToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('user_id=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertArrayHasKey('user_id', $params);
        $parts = explode('.', $params['user_id']);
        $this->assertCount(2, $parts);

        $decodedPayload = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('user@example.com', $decodedPayload['uid']);
        $this->assertArrayHasKey('ts', $decodedPayload);

        $expectedSig = hash_hmac('sha256', $parts[0], 'secret');
        $this->assertSame($expectedSig, $parts[1]);
    }

    public function testRenderWidgetOutputsScriptTag(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123');
        $html = $extension->renderWidget();

        $this->assertStringStartsWith('<script async src="', $html);
        $this->assertStringEndsWith('"></script>', $html);
        $this->assertStringContainsString('store_id=store-123', $html);
    }

    public function testRenderWidgetReturnsEmptyWhenNoStoreId(): void
    {
        $extension = $this->createExtension('');
        $html = $extension->renderWidget();

        $this->assertSame('', $html);
    }

    public function testGetWidgetUrlFallsBackWhenNoRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension();
        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('language=en', $url);
    }

    public function testRenderCartWidgetOutputsConfigAndScripts(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook');
        $html = $extension->renderCartWidget();

        $this->assertStringContainsString('window.emporiqaConfig', $html);
        $this->assertStringContainsString('"storeId":"store-123"', $html);
        $this->assertStringContainsString('"language":"en"', $html);
        $this->assertStringContainsString('"userId":0', $html);
        $this->assertStringContainsString('"cartEnabled":true', $html);
        $this->assertStringContainsString('emporiqa-cart.js', $html);
        $this->assertStringContainsString('emporiqa-widget-loader.js', $html);
    }

    public function testRenderCartWidgetReturnsEmptyWhenNoStoreId(): void
    {
        $extension = $this->createExtension('');
        $html = $extension->renderCartWidget();

        $this->assertSame('', $html);
    }

    public function testRenderCartWidgetIncludesUserIdForAuthenticatedUser(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123');
        $html = $extension->renderCartWidget();

        $this->assertStringContainsString('"userId":', $html);
        $this->assertStringNotContainsString('"userId":0', $html);
    }

    public function testRenderCartWidgetWithCartDisabled(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'test-secret', false);
        $html = $extension->renderCartWidget();

        $this->assertStringContainsString('"cartEnabled":false', $html);
    }
}
