<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Twig;

use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Twig\EmporiqaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
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
        bool $cartEnabled = true,
        ?ChannelContextInterface $channelContext = null,
        ?CurrencyContextInterface $currencyContext = null,
        array $enabledLanguages = [],
    ): EmporiqaExtension {
        return new EmporiqaExtension(
            $storeId,
            $webhookUrl,
            $webhookSecret,
            $this->requestStack,
            new ChannelMappingResolver(),
            $this->security,
            $cartEnabled,
            $channelContext,
            $currencyContext,
            $enabledLanguages,
        );
    }

    /**
     * The config the script tag for $file carries, decoded as the browser does.
     */
    private function configOf(string $html, string $file): array
    {
        $this->assertSame(1, preg_match('#<script src="[^"]*' . preg_quote($file, '#') . '[^"]*" data-emporiqa-config="([^"]*)"#', $html, $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNoInlineScript(string $html): void
    {
        $this->assertSame(0, preg_match('#<script(?![^>]*\ssrc=)#', $html), 'a strict Content-Security-Policy blocks an inline script');
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

    public function testGetWidgetUrlPassesFullLocaleCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('de_DE');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension();
        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('language=de_DE', $url);
    }

    public function testGetWidgetUrlNeverCarriesTheUserToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $url = $extension->getWidgetUrl();

        // URLs end up in server logs and Referer headers.
        $this->assertStringNotContainsString('user_id', $url);
        $this->assertStringNotContainsString('user%40example.com', $url);
    }

    public function testRenderWidgetOutputsScriptTag(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123');
        $html = $extension->renderWidget();

        $this->assertStringContainsString('<script src="', $html);
        $this->assertStringContainsString('store_id=store-123', $html);
        $this->assertStringContainsString('language=en', $html);
        $this->assertStringContainsString('async', $html);
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
        $this->assertStringNotContainsString('window.emporiqaConfig', $html);
        $this->assertStringNotContainsString('emporiqa-cart.js', $html);
        $this->assertStringNotContainsString('user_id', $html);
    }

    public function testRenderWidgetReturnsEmptyWhenNoStoreId(): void
    {
        $extension = $this->createExtension('');
        $html = $extension->renderWidget();

        $this->assertSame('', $html);
    }

    /**
     * A locale that is not in enabled_languages is neither synced nor offered: its pages show no chat.
     */
    public function testUntickedLocaleRendersNoWidget(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('fr_FR');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = $this->createExtension(enabledLanguages: ['en_US', 'de_DE']);

        $this->assertSame('', $extension->renderWidget());
        $this->assertSame('', $extension->renderCartWidget());
    }

    public function testTickedLocaleRendersTheWidget(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('de_DE');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension(enabledLanguages: ['en_US', 'de_DE']);

        $this->assertStringContainsString('language=de_DE', $extension->renderWidget());
        $this->assertStringContainsString('language=de_DE', $extension->renderCartWidget());
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

        $this->assertNoInlineScript($html);
        $this->assertSame([
            'language' => 'en',
            'currency' => '',
            'channel' => '',
            'cartEnabled' => true,
            'csrfUrl' => '/emporiqa/api/csrf-token',
            'cartUrl' => '/emporiqa/api/cart',
        ], $this->configOf($html, 'emporiqa-cart.js'));
        $this->assertSame(['url' => '/emporiqa/api/customer-token'], $this->configOf($html, 'emporiqa-customer-token.js'));
        $this->assertStringContainsString('store_id=store-123', $html);
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
    }

    public function testTheConfigIsEscapedForTheAttribute(): void
    {
        $locale = 'en"><script>alert(1)</script>\'&';
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn($locale);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $html = $this->createExtension()->renderCartWidget();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertNoInlineScript($html);
        // Decoding back to the whole value proves no quote ended the attribute early.
        $this->assertSame($locale, $this->configOf($html, 'emporiqa-cart.js')['language']);
    }

    public function testRenderCartWidgetReturnsEmptyWhenNoStoreId(): void
    {
        $extension = $this->createExtension('');
        $html = $extension->renderCartWidget();

        $this->assertSame('', $html);
    }

    public function testRenderWidgetKeepsTheTokenOffThePageForLoggedInUser(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $html = $extension->renderWidget();

        $this->assertStringNotContainsString('user_id', $html);
        $this->assertNoInlineScript($html);
        $this->assertSame(['url' => '/emporiqa/api/customer-token'], $this->configOf($html, 'emporiqa-customer-token.js'));
        $this->assertStringContainsString('emporiqa-customer-token.js?v=', $html);
    }

    /**
     * A shared page cache hands one shopper's page to the next, so nothing
     * in it may depend on who is signed in.
     */
    public function testSignedInAndGuestGetTheSamePage(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $user = $this->createMock(UserInterface::class);
        $this->security->method('getUser')->willReturnOnConsecutiveCalls($user, $user, null, null);

        $extension = $this->createExtension();
        $signedIn = $extension->renderWidget() . $extension->renderCartWidget();

        $this->assertSame($signedIn, $extension->renderWidget() . $extension->renderCartWidget());
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

        $this->assertStringNotContainsString('user_id', $html);
    }

    public function testRenderCartWidgetWithCartDisabled(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'test-secret', false);
        $html = $extension->renderCartWidget();

        $this->assertFalse($this->configOf($html, 'emporiqa-cart.js')['cartEnabled']);
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
            true,
            $this->channelContext,
        );

        $html = $extension->renderWidget();

        $this->assertStringContainsString('currency=USD', $html);
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
            true,
            $this->channelContext,
        );

        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('currency=GBP', $url);
        $this->assertStringContainsString('channel=B2B', $url);
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
            true,
            $this->channelContext,
            $currencyContext,
        );

        $html = $extension->renderWidget();
        $this->assertStringContainsString('currency=USD', $html);

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
            true,
            $this->channelContext,
            $currencyContext,
        );

        $html = $extension->renderWidget();
        $this->assertStringContainsString('currency=EUR', $html);
    }

    public function testAutoDetectChannelWithThreeChannels(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $ch1 = $this->createChannelWithCurrency('WEB', 'EUR');
        $ch2 = $this->createChannelWithCurrency('B2B', 'USD');
        $ch3 = $this->createChannelWithCurrency('WHOLESALE', 'GBP');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2, $ch3]);

        // Current channel is the third one (WHOLESALE)
        $this->channelContext->method('getChannel')->willReturn($ch3);

        $extension = new EmporiqaExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver($channelRepo),
            $this->security,
            true,
            $this->channelContext,
        );

        $url = $extension->getWidgetUrl();

        $this->assertStringContainsString('channel=WHOLESALE', $url);
        $this->assertStringContainsString('currency=GBP', $url);
    }

    public function testAutoDetectChannelFirstChannelReturnsChannelCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $ch1 = $this->createChannelWithCurrency('WEB', 'EUR');
        $ch2 = $this->createChannelWithCurrency('B2B', 'USD');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2]);

        // Current channel is the first one (WEB)
        $this->channelContext->method('getChannel')->willReturn($ch1);

        $extension = new EmporiqaExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver($channelRepo),
            $this->security,
            true,
            $this->channelContext,
        );

        $url = $extension->getWidgetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('WEB', $params['channel']);
    }

    public function testAutoDetectSingleChannelReturnsChannelCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $ch1 = $this->createChannelWithCurrency('WEB', 'EUR');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1]);

        $this->channelContext->method('getChannel')->willReturn($ch1);

        $extension = new EmporiqaExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver($channelRepo),
            $this->security,
            true,
            $this->channelContext,
        );

        $url = $extension->getWidgetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('WEB', $params['channel']);
    }

    public function testChannelContextThrowingReturnsEmptyChannel(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willThrowException(
            new \RuntimeException('No channel found')
        );

        $extension = $this->createExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            true,
            $channelContext,
        );

        $url = $extension->getWidgetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('', $params['channel']);
        $this->assertSame('', $params['currency']);
    }

    public function testRenderCartWidgetIncludesAuthenticatedUserInfo(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('admin@example.com');
        $this->security->method('getUser')->willReturn($user);

        $extension = $this->createExtension('store-123', 'https://api.emporiqa.com/webhook', 'secret');
        $html = $extension->renderCartWidget();

        $this->assertArrayNotHasKey('authenticated', $this->configOf($html, 'emporiqa-cart.js'));
        $this->assertStringNotContainsString('user_id', $html);
        $this->assertStringContainsString('emporiqa-cart.js?v=', $html);
        $this->assertStringContainsString('emporiqa-customer-token.js?v=', $html);
    }

    public function testRenderWidgetWithNullSecurity(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new EmporiqaExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver(),
            null,
        );

        $html = $extension->renderWidget();
        $this->assertStringContainsString('store_id=store-123', $html);
        $this->assertStringNotContainsString('user_id=', $html);
    }

    public function testRenderCartWidgetWithNullSecurity(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $extension = new EmporiqaExtension(
            'store-123',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver(),
            null,
        );

        $html = $extension->renderCartWidget();
        $this->assertSame('en', $this->configOf($html, 'emporiqa-cart.js')['language']);
        $this->assertStringNotContainsString('user_id=', $html);
    }

    public function testNoCurrencyOrChannelContextReturnsEmptyValues(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->security->method('getUser')->willReturn(null);

        // No channel context, no currency context
        $extension = $this->createExtension();
        $url = $extension->getWidgetUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('', $params['currency']);
        $this->assertSame('', $params['channel']);
    }

    public function testTheTokenUrlComesFromTheRouterForSubPathInstalls(): void
    {
        $request = Request::create('/shop/en_US/');
        $request->setLocale('en_US');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->with('emporiqa_customer_token')->willReturn('/shop/emporiqa/api/customer-token');

        $extension = new EmporiqaExtension(
            'test-store',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver(),
            $this->security,
            true,
            null,
            null,
            [],
            $router,
        );

        $this->assertSame(['url' => '/shop/emporiqa/api/customer-token'], $this->configOf($extension->renderWidget(), 'emporiqa-customer-token.js'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function offSitePrefixes(): array
    {
        return [
            'two slashes' => ['//evil.example'],
            'slash and backslash' => ['/\\evil.example'],
            'tab between the slashes' => ["/\t/evil.example"],
            'newline between the slashes' => ["/\n/evil.example"],
        ];
    }

    #[DataProvider('offSitePrefixes')]
    public function testABasePathNamingAnotherHostIsIgnored(string $prefix): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $request->method('getBasePath')->willReturn($prefix);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $html = $this->createExtension()->renderCartWidget();

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('<script src="/bundles/emporiqaplugin/js/emporiqa-cart.js?v=', $html);
    }

    #[DataProvider('offSitePrefixes')]
    public function testARouterUrlNamingAnotherHostIsReplaced(string $prefix): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getLocale')->willReturn('en');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => $prefix . '/' . $route);

        $extension = new EmporiqaExtension(
            'test-store',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver(),
            urlGenerator: $router,
        );
        $html = $extension->renderCartWidget();

        $this->assertStringNotContainsString('evil.example', $html);
        $config = $this->configOf($html, 'emporiqa-cart.js');
        $this->assertSame('/emporiqa/api/csrf-token', $config['csrfUrl']);
        $this->assertSame('/emporiqa/api/cart', $config['cartUrl']);
        $this->assertSame(['url' => '/emporiqa/api/customer-token'], $this->configOf($html, 'emporiqa-customer-token.js'));
    }

    public function testTheCartUrlsAndScriptsFollowASubPathInstall(): void
    {
        $request = Request::create('/shop/en_US/', server: ['SCRIPT_FILENAME' => '/var/www/public/index.php', 'SCRIPT_NAME' => '/shop/index.php', 'PHP_SELF' => '/shop/index.php']);
        $request->setLocale('en_US');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturnMap([
            ['emporiqa_customer_token', [], 1, '/shop/emporiqa/api/customer-token'],
            ['emporiqa_cart_csrf_token', [], 1, '/shop/emporiqa/api/csrf-token'],
            ['emporiqa_cart_get', [], 1, '/shop/emporiqa/api/cart'],
        ]);

        $extension = new EmporiqaExtension(
            'test-store',
            'https://api.emporiqa.com/webhook',
            'test-secret',
            $this->requestStack,
            new ChannelMappingResolver(),
            urlGenerator: $router,
        );
        $html = $extension->renderCartWidget();

        $config = $this->configOf($html, 'emporiqa-cart.js');
        $this->assertSame('/shop/emporiqa/api/csrf-token', $config['csrfUrl']);
        $this->assertSame('/shop/emporiqa/api/cart', $config['cartUrl']);
        $this->assertStringContainsString('<script src="/shop/bundles/emporiqaplugin/js/emporiqa-cart.js?v=', $html);
        $this->assertStringContainsString('<script src="/shop/bundles/emporiqaplugin/js/emporiqa-customer-token.js?v=', $html);
    }
}
