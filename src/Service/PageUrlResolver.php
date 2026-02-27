<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;

/**
 * Default no-op page URL resolver.
 *
 * Decorate this service to provide page URLs for your CMS pages.
 * Example: if using BitBag CMS plugin, decorate with a resolver
 * that generates routes for BitBag pages.
 */
class PageUrlResolver implements PageUrlResolverInterface
{
    use TranslationHelperTrait;

    private bool $warned = false;

    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    public function resolveUrl(PageInterface $page, string $locale): string
    {
        if (!$this->warned) {
            $this->logger?->info(
                'Emporiqa: Using default PageUrlResolver which returns empty URLs. '
                . 'Decorate Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface to provide real page URLs.',
            );
            $this->warned = true;
        }

        return '';
    }
}
