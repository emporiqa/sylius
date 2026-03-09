<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Twig;

use Emporiqa\SyliusPlugin\Service\UserTokenGenerator;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
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
        private ?ChannelRepositoryInterface $channelRepository = null,
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
     * Renders the widget embed script tag.
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

        $widgetUrl = $this->buildWidgetUrl();

        return '<script src="' . htmlspecialchars($widgetUrl, ENT_QUOTES, 'UTF-8') . '" async crossorigin="anonymous"></script>';
    }

    /**
     * Renders the cart-enabled widget with inline config and embed script.
     */
    public function renderCartWidget(): string
    {
        if (empty($this->storeId)) {
            return '';
        }

        $config = $this->buildWidgetConfig($this->cartEnabled);
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        $widgetUrl = $this->buildWidgetUrl();

        $html = '<script>' . "\n";
        $html .= 'window.emporiqaConfig = ' . $configJson . ';' . "\n";
        $html .= '</script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-cart.js"></script>' . "\n";
        $html .= '<script src="' . htmlspecialchars($widgetUrl, ENT_QUOTES, 'UTF-8') . '" async crossorigin="anonymous"></script>';

        return $html;
    }

    private function buildWidgetConfig(bool $cartEnabled): array
    {
        $user = $this->security->getUser();

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

        $user = $this->security->getUser();
        if ($user !== null && !empty($this->webhookSecret)) {
            $params['user_id'] = UserTokenGenerator::generate(
                $user->getUserIdentifier(),
                $this->webhookSecret,
            );
        }

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
            $channel = $this->channelContext->getChannel();
            $code = $channel->getCode() ?? '';

            if (!empty($this->channelMapping)) {
                return $this->channelMapping[$code] ?? '';
            }

            return $this->autoDetectChannelKey($code);
        } catch (\Throwable) {
            return '';
        }
    }

    private function autoDetectChannelKey(string $currentCode): string
    {
        if ($this->channelRepository === null) {
            return '';
        }

        $channels = $this->channelRepository->findAll();
        if (count($channels) <= 1) {
            return '';
        }

        $first = true;
        foreach ($channels as $ch) {
            $code = $ch->getCode();
            if ($first) {
                if ($code === $currentCode) {
                    return '';
                }
                $first = false;
            } else {
                if ($code === $currentCode) {
                    return strtolower($code);
                }
            }
        }

        return '';
    }
}
