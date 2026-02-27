<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Twig;

use Emporiqa\SyliusPlugin\Controller\UserTokenController;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EmporiqaExtension extends AbstractExtension
{
    public function __construct(
        private string $storeId,
        private string $webhookUrl,
        private string $webhookSecret,
        private RequestStack $requestStack,
        private Security $security,
        private array $channelMapping = [],
        private bool $cartEnabled = true,
        private ?ChannelContextInterface $channelContext = null,
        private ?CurrencyContextInterface $currencyContext = null,
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
     * Renders the widget with inline config.
     *
     * Anonymous pages contain no user-specific data (safe for Varnish/CDN).
     * Authenticated pages include a signed user token — Symfony already
     * serves authenticated responses as Cache-Control: private, so this
     * is safe and avoids an extra AJAX round-trip.
     */
    public function renderWidget(): string
    {
        if (empty($this->storeId)) {
            return '';
        }

        $config = $this->buildWidgetConfig(false);
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $html = '<script>' . "\n";
        $html .= 'window.emporiqaConfig = ' . $configJson . ';' . "\n";
        $html .= '</script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-widget-loader.js"></script>';

        return $html;
    }

    /**
     * Renders the cart-enabled widget with inline config.
     */
    public function renderCartWidget(): string
    {
        if (empty($this->storeId)) {
            return '';
        }

        $config = $this->buildWidgetConfig($this->cartEnabled);
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $html = '<script>' . "\n";
        $html .= 'window.emporiqaConfig = ' . $configJson . ';' . "\n";
        $html .= '</script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-cart.js"></script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-widget-loader.js"></script>';

        return $html;
    }

    private function buildWidgetConfig(bool $cartEnabled): array
    {
        $baseDomain = parse_url($this->webhookUrl, PHP_URL_HOST) ?: 'emporiqa.com';
        $user = $this->security->getUser();

        $config = [
            'storeId' => $this->storeId,
            'widgetBaseUrl' => 'https://' . $baseDomain . '/chat/embed/',
            'language' => $this->getShortLocale(),
            'currency' => $this->getCurrentCurrencyCode(),
            'channel' => $this->getCurrentChannelKey(),
            'authenticated' => $user !== null,
            'cartEnabled' => $cartEnabled,
        ];

        if ($user !== null && !empty($this->webhookSecret)) {
            $config['userId'] = UserTokenController::generateUserToken(
                $user->getUserIdentifier(),
                $this->webhookSecret,
            );
        }

        return $config;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    /**
     * Returns a direct widget URL with embedded user token.
     *
     * @deprecated Use renderWidget() or renderCartWidget() instead.
     *             This method embeds user-specific data and is not safe for cached pages.
     */
    public function getWidgetUrl(): string
    {
        $baseDomain = parse_url($this->webhookUrl, PHP_URL_HOST) ?: 'emporiqa.com';

        $params = [
            'store_id' => $this->storeId,
            'language' => $this->getShortLocale(),
            'currency' => $this->getCurrentCurrencyCode(),
            'channel' => $this->getCurrentChannelKey(),
        ];

        $user = $this->security->getUser();
        if ($user !== null) {
            $params['user_id'] = UserTokenController::generateUserToken(
                $user->getUserIdentifier(),
                $this->webhookSecret,
            );
        }

        return 'https://' . $baseDomain . '/chat/embed/?' . http_build_query($params);
    }

    private function getShortLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getLocale() ?? 'en';
        if (strlen($locale) > 2) {
            $locale = substr($locale, 0, 2);
        }

        return $locale;
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
            $channel = $this->channelContext->getChannel();
            $code = $channel->getCode() ?? '';

            return $this->channelMapping[$code] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
