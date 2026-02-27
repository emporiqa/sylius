<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Twig;

use Emporiqa\SyliusPlugin\Twig\EmporiqaExtension;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

class EmporiqaExtensionTest extends TestCase
{
    private RequestStack $requestStack;
    private Security $security;
    private ChannelContextInterface $channelContext;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->security = $this->createMock(Security::class);
        $this->channelContext = $this->createMock(ChannelContextInterface::class);
    }

    private function createExtension(
        string $storeId = 'test-store',
        string $webhookUrl = 'https://api.emporiqa.com/webhook',
        string $webhookSecret = 'test-secret',
        array $channelMapping = [],
        bool $cartEnabled = true,
        ?ChannelContextInterface $channelContext = null,
        ?CurrencyContextInterface $currencyContext = null,
    ): EmporiqaExtension {
        return new EmporiqaExtension(
            $storeId,
            $webhookUrl,
            $webhookSecret,
            $this->requestStack,
            $this->security,
            $channelMapping,
            $cartEnabled,
            $channelContext,
            $currencyContext,
        );
    }

    private function createChannelWithCurrency(string $code = 'WEB', string $currencyCode = 'EUR'): ChannelInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($currencyCode);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getBaseCurrency')->willReturn($currency);

        return $channel;
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

    public function testRenderWidgetUsesLoaderScript(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123');
        $html = $extension->renderWidget();

        $this->assertStringContainsString('window.emporiqaConfig', $html);
        $this->assertStringContainsString('"storeId":"store-123"', $html);
        $this->assertStringContainsString('"cartEnabled":false', $html);
        $this->assertStringContainsString('"currency":', $html);
        $this->assertStringContainsString('"channel":', $html);
        $this->assertStringContainsString('"authenticated":false', $html);
        $this->assertStringContainsString('emporiqa-widget-loader.js', $html);
        $this->assertStringNotContainsString('emporiqa-cart.js', $html);
        $this->assertStringNotContainsString('"userId"', $html);
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
        $this->assertStringContainsString('"authenticated":false', $html);
        $this->assertStringContainsString('"cartEnabled":true', $html);
        $this->assertStringContainsString('"currency":', $html);
        $this->assertStringContainsString('"channel":', $html);
        $this->assertStringNotContainsString('"userId"', $html);
        $this->assertStringContainsString('emporiqa-cart.js', $html);
        $this->assertStringContainsString('emporiqa-widget-loader.js', $html);
    }

    public function testRenderCartWidgetReturnsEmptyWhenNoStoreId(): void
    {
        $extension = $this->createExtension('');
        $html = $extension->renderCartWidget();

        $this->assertSame('', $html);
    }

    public function testRenderCartWidgetIncludesUserTokenForLoggedInUser(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $html = $extension->renderCartWidget();

        $this->assertStringContainsString('"authenticated":true', $html);
        $this->assertStringContainsString('"userId":"', $html);

        // Extract the token from the config JSON and verify its structure
        preg_match('/"userId":"([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        $parts = explode('.', $matches[1]);
        $this->assertCount(2, $parts);

        $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('user@example.com', $decoded['uid']);
        $this->assertArrayHasKey('ts', $decoded);
    }

    public function testRenderWidgetIncludesUserTokenForLoggedInUser(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $html = $extension->renderWidget();

        $this->assertStringContainsString('"authenticated":true', $html);
        $this->assertStringContainsString('"userId":"', $html);
    }

    public function testRenderWidgetOmitsUserIdWhenNoSecret(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', '');
        $html = $extension->renderWidget();

        $this->assertStringContainsString('"authenticated":true', $html);
        $this->assertStringNotContainsString('"userId"', $html);
    }

    public function testRenderCartWidgetWithCartDisabled(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'test-secret', [], false);
        $html = $extension->renderCartWidget();

        $this->assertStringContainsString('"cartEnabled":false', $html);
    }

    public function testWidgetIncludesCurrencyAndChannelFromContext(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $channel = $this->createChannelWithCurrency('FASHION_WEB', 'USD');
        $this->channelContext->method('getChannel')->willReturn($channel);

        $extension = $this->createExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            ['FASHION_WEB' => '', 'B2B' => 'b2b'],
            true,
            $this->channelContext,
        );

        $html = $extension->renderWidget();

        $this->assertStringContainsString('"currency":"USD"', $html);
        $this->assertStringContainsString('"channel":""', $html);
    }

    public function testGetWidgetUrlIncludesCurrencyAndChannel(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $channel = $this->createChannelWithCurrency('B2B', 'GBP');
        $this->channelContext->method('getChannel')->willReturn($channel);

        $extension = $this->createExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            ['WEB' => '', 'B2B' => 'b2b'],
            true,
            $this->channelContext,
        );

        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('currency=GBP', $url);
        $this->assertStringContainsString('channel=b2b', $url);
    }

    public function testCurrencyContextTakesPriorityOverChannelBaseCurrency(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $channel = $this->createChannelWithCurrency('WEB', 'EUR');
        $this->channelContext->method('getChannel')->willReturn($channel);

        $currencyContext = $this->createMock(CurrencyContextInterface::class);
        $currencyContext->method('getCurrencyCode')->willReturn('USD');

        $extension = $this->createExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            [],
            true,
            $this->channelContext,
            $currencyContext,
        );

        $html = $extension->renderWidget();
        $this->assertStringContainsString('"currency":"USD"', $html);

        $url = $extension->getWidgetUrl();
        $this->assertStringContainsString('currency=USD', $url);
    }

    public function testCurrencyFallsBackToChannelBaseCurrencyWhenContextThrows(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $channel = $this->createChannelWithCurrency('WEB', 'EUR');
        $this->channelContext->method('getChannel')->willReturn($channel);

        $currencyContext = $this->createMock(CurrencyContextInterface::class);
        $currencyContext->method('getCurrencyCode')->willThrowException(
            new \RuntimeException('No currency found')
        );

        $extension = $this->createExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            [],
            true,
            $this->channelContext,
            $currencyContext,
        );

        $html = $extension->renderWidget();
        $this->assertStringContainsString('"currency":"EUR"', $html);
    }
}
