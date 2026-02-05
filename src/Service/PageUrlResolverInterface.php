<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;

interface PageUrlResolverInterface
{
    /**
     * Resolve the URL for a page in the given locale.
     *
     * The host project should decorate this service to provide real URLs
     * using its own router and route definitions.
     */
    public function resolveUrl(PageInterface $page, string $locale): string;
}
