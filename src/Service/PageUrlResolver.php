<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;

class PageUrlResolver implements PageUrlResolverInterface
{
    use TranslationHelperTrait;

    public function resolveUrl(PageInterface $page, string $locale): string
    {
        return '';
    }
}
