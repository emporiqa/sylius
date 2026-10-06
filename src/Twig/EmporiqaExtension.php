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

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR;

    /**
     * @param string   $webhookSecret    @deprecated unused since 1.11.0 (the
     *                                   customer token left the page); kept for
     *                                   BC with custom service definitions that
     *                                   pass it; removed in the next major
     * @param string[] $enabledLanguages the synced locales; empty means all
     */
    public function __construct( // @phpstan-ignore constructor.unusedParameter ($webhookSecret, kept for existing service definitions)
        private string $storeId,
        private string $webhookUrl,
        string $webhookSecret,
        private RequestStack $requestStack,
        private ChannelMappingResolver $channelMappingResolver,
        private ?Security $security = null,
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
     * Renders the widget embed script tag and the customer-token script.
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
     * Renders the cart-enabled widget with inline config and embed script.
     */
    public function renderCartWidget(): string
    {
        if (!$this->isShown()) {
            return '';
        }

        $config = $this->buildWidgetConfig($this->cartEnabled);
        $configJson = json_encode($config, self::JSON_FLAGS);

        $html = '<script>' . "\n";
        $html .= 'window.emporiqaConfig = ' . $configJson . ';' . "\n";
        $html .= '</script>' . "\n";
        $html .= '<script src="' . $this->assetUrl('emporiqa-cart.js') . '"></script>' . "\n";
        $html .= $this->renderTokenScript() . "\n";
        $html .= $this->renderEmbedScript();

        return $html;
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
     * `authenticated` only spares a guest the token request; the endpoint
     * itself answers a guest with an empty token.
     */
    private function renderTokenScript(): string
    {
        $config = json_encode([
            'url' => $this->tokenUrl(),
            'authenticated' => $this->security?->getUser() !== null,
        ], self::JSON_FLAGS);

        return '<script>window.emporiqaTokenConfig = ' . $config . ';</script>' . "\n"
            . '<script src="' . $this->assetUrl('emporiqa-customer-token.js') . '" defer></script>';
    }

    /**
     * From the router, so a shop installed under a sub-path asks the right URL.
     */
    private function tokenUrl(): string
    {
        try {
            return $this->urlGenerator?->generate(self::TOKEN_ROUTE) ?? self::TOKEN_PATH;
        } catch (\Throwable) {
            return self::TOKEN_PATH;
        }
    }

    /**
     * Versioned, so browsers load the new file after a plugin update.
     */
    private function assetUrl(string $file): string
    {
        return self::ASSET_BASE . $file . '?v=' . EmporiqaPlugin::VERSION;
    }

    private function buildWidgetConfig(bool $cartEnabled): array
    {
        $user = $this->security?->getUser();

        return [
            'language' => $this->getLocale(),
            'currency' => $this->getCurrentCurrencyCode(),
            'channel' => $this->getCurrentChannelKey(),
            'authenticated' => $user !== null,
            'cartEnabled' => $cartEnabled,
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
