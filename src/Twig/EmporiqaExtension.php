<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Twig;

use Emporiqa\SyliusPlugin\EmporiqaPlugin;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EmporiqaExtension extends AbstractExtension
{
    private const ASSET_BASE = '/bundles/emporiqaplugin/js/';

    private const TOKEN_ROUTE = 'emporiqa_customer_token';

    private const TOKEN_PATH = '/emporiqa/api/customer-token';

    private const CSRF_ROUTE = 'emporiqa_cart_csrf_token';

    private const CSRF_PATH = '/emporiqa/api/csrf-token';

    private const CART_ROUTE = 'emporiqa_cart_get';

    private const CART_PATH = '/emporiqa/api/cart';

    private const OFF_SITE_PATH = '#^//|[\\\\\x00-\x1f]#';

    private const JSON_FLAGS =JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR;

    /**
     * @param string   $webhookSecret    @deprecated unused since 1.11.0 (the
     *                                   customer token left the page); kept for
     *                                   BC with custom service definitions that
     *                                   pass it; removed in the next major
     * @param ?Security $security        @deprecated unused since 1.12.0 (nothing
     *                                   about the shopper is in the page); kept
     *                                   for BC, removed in the next major
     * @param string[] $enabledLanguages the synced locales; empty means all
     */
    public function __construct( // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter ($webhookSecret and $security, kept for existing service definitions)
        private string $storeId,
        private string $webhookUrl,
        string $webhookSecret,
        private RequestStack $requestStack,
        private ChannelMappingResolver $channelMappingResolver,
        ?Security $security = null,
        private bool $cartEnabled = true,
        private ?ChannelContextInterface $channelContext = null,
        private ?CurrencyContextInterface $currencyContext = null,
        private array $enabledLanguages = [],
        private ?UrlGeneratorInterface $urlGenerator = null,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('emporiqa_widget', [$this, 'renderWidget'], ['is_safe' => ['html']]),
            new TwigFunction('emporiqa_cart_widget', [$this, 'renderCartWidget'], ['is_safe' => ['html']]),
            new TwigFunction('emporiqa_store_id', [$this, 'getStoreId']),
            new TwigFunction('emporiqa_widget_url', [$this, 'getWidgetUrl']),
        ];
    }

    /**
     * Renders the customer-token script and the widget embed script.
     *
     * The signed-in shopper's token is never in the page or the widget URL:
     * a full-page cache could hand it to another customer, and URLs end up
     * in server logs. The token script fetches it from an uncached endpoint
     * when the chat opens and hands it to the widget on a MessageChannel.
     */
    public function renderWidget(): string
    {
        if (!$this->isShown()) {
            return '';
        }

        return $this->renderTokenScript() . "\n" . $this->renderEmbedScript();
    }

    /**
     * Renders the cart handler, the customer-token script and the embed
     * script. No inline script, so a Content-Security-Policy without
     * 'unsafe-inline' allows it all.
     */
    public function renderCartWidget(): string
    {
        if (!$this->isShown()) {
            return '';
        }

        return $this->renderScript('emporiqa-cart.js', $this->buildWidgetConfig($this->cartEnabled)) . "\n"
            . $this->renderTokenScript() . "\n"
            . $this->renderEmbedScript();
    }

    /**
     * A locale that is not in enabled_languages is neither synced nor
     * offered: its pages show no chat.
     */
    private function isShown(): bool
    {
        if (empty($this->storeId)) {
            return false;
        }

        return $this->enabledLanguages === [] || \in_array($this->getLocale(), $this->enabledLanguages, true);
    }

    private function renderEmbedScript(): string
    {
        return '<script src="' . htmlspecialchars($this->buildWidgetUrl(), ENT_QUOTES, 'UTF-8') . '" async crossorigin="anonymous"></script>';
    }

    /**
     * Nothing about the shopper is in the page, so a shared page cache can
     * serve it to anyone: the endpoint answers a guest with an empty token.
     */
    private function renderTokenScript(): string
    {
        return $this->renderScript('emporiqa-customer-token.js', [
            'url' => $this->routeUrl(self::TOKEN_ROUTE, self::TOKEN_PATH),
        ], ' defer');
    }

    /**
     * The script reads its config from data-emporiqa-config through
     * document.currentScript.
     */
    private function renderScript(string $file, array $config, string $attributes = ''): string
    {
        return '<script src="' . htmlspecialchars($this->assetUrl($file), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-emporiqa-config="' . htmlspecialchars(json_encode($config, self::JSON_FLAGS), ENT_QUOTES, 'UTF-8') . '"'
            . $attributes . '></script>';
    }

    /**
     * From the router, so a shop installed under a sub-path asks the right URL.
     */
    private function routeUrl(string $route, string $fallback): string
    {
        try {
            $url = $this->urlGenerator?->generate($route) ?? $fallback;
        } catch (\Throwable) {
            return $fallback;
        }

        return $this->pointsOffSite($url) ? $fallback : $url;
    }

    /**
     * The router and the base path both take a forwarded prefix. Browsers read
     * "//host", "/\host" and "/<tab>/host" alike as another host, and the
     * script and fetch URLs must never go there.
     */
    private function pointsOffSite(string $path): bool
    {
        return preg_match(self::OFF_SITE_PATH, $path) === 1;
    }

    /**
     * Versioned, so browsers load the new file after a plugin update; under
     * the request's base path, so it is found when the shop is in a sub-path.
     */
    private function assetUrl(string $file): string
    {
        $basePath = $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';
        if ($this->pointsOffSite($basePath)) {
            $basePath = '';
        }

        return $basePath . self::ASSET_BASE . $file . '?v=' . EmporiqaPlugin::VERSION;
    }

    private function buildWidgetConfig(bool $cartEnabled): array
    {
        return [
            'language' => $this->getLocale(),
            'currency' => $this->getCurrentCurrencyCode(),
            'channel' => $this->getCurrentChannelKey(),
            'cartEnabled' => $cartEnabled,
            'csrfUrl' => $this->routeUrl(self::CSRF_ROUTE, self::CSRF_PATH),
            'cartUrl' => $this->routeUrl(self::CART_ROUTE, self::CART_PATH),
        ];
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    /**
     * @deprecated Use renderWidget() or renderCartWidget() instead.
     */
    public function getWidgetUrl(): string
    {
        return $this->buildWidgetUrl();
    }

    private function buildWidgetUrl(): string
    {
        $baseDomain = parse_url($this->webhookUrl, PHP_URL_HOST) ?: 'emporiqa.com';

        $params = [
            'store_id' => $this->storeId,
            'language' => $this->getLocale(),
            'currency' => $this->getCurrentCurrencyCode(),
            'channel' => $this->getCurrentChannelKey(),
        ];

        return 'https://' . $baseDomain . '/chat/embed/?' . http_build_query($params);
    }

    private function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->getLocale() ?? 'en';
    }

    private function getCurrentCurrencyCode(): string
    {
        if ($this->currencyContext !== null) {
            try {
                return $this->currencyContext->getCurrencyCode();
            } catch (\Throwable) {
                // Fall through to channel base currency
            }
        }

        if ($this->channelContext !== null) {
            try {
                return $this->channelContext->getChannel()->getBaseCurrency()?->getCode() ?? '';
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function getCurrentChannelKey(): string
    {
        if ($this->channelContext === null) {
            return '';
        }

        try {
            return $this->channelMappingResolver->resolveKey($this->channelContext->getChannel());
        } catch (\Throwable) {
            return '';
        }
    }
}
