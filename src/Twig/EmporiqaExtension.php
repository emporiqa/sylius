<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Twig;

use Emporiqa\SyliusPlugin\Controller\UserTokenController;
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
        private bool $cartEnabled = true,
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

    public function renderWidget(): string
    {
        if (empty($this->storeId)) {
            return '';
        }

        $widgetUrl = $this->getWidgetUrl();
        return sprintf('<script async src="%s"></script>', htmlspecialchars($widgetUrl, ENT_QUOTES, 'UTF-8'));
    }

    public function renderCartWidget(): string
    {
        if (empty($this->storeId)) {
            return '';
        }

        $baseDomain = parse_url($this->webhookUrl, PHP_URL_HOST) ?: 'emporiqa.com';
        $locale = $this->getShortLocale();
        $userId = $this->getCustomerId();

        $config = [
            'storeId' => $this->storeId,
            'widgetBaseUrl' => 'https://' . $baseDomain . '/chat/embed/',
            'language' => $locale,
            'userId' => $userId,
            'cartEnabled' => $this->cartEnabled,
        ];

        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $html = '<script>' . "\n";
        $html .= 'window.emporiqaConfig = ' . $configJson . ';' . "\n";
        $html .= '</script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-cart.js"></script>' . "\n";
        $html .= '<script src="/bundles/emporiqaplugin/js/emporiqa-widget-loader.js"></script>';

        return $html;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getWidgetUrl(): string
    {
        $baseDomain = parse_url($this->webhookUrl, PHP_URL_HOST) ?: 'emporiqa.com';

        $locale = $this->getShortLocale();

        $params = [
            'store_id' => $this->storeId,
            'language' => $locale,
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

    private function getCustomerId(): int
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return 0;
        }

        if (method_exists($user, 'getCustomer') && $user->getCustomer()?->getId()) {
            return (int) $user->getCustomer()->getId();
        }

        return (int) crc32($user->getUserIdentifier());
    }
}
