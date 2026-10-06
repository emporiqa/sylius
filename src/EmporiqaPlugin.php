<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class EmporiqaPlugin extends Bundle
{
    /**
     * Sent as X-Emporiqa-Plugin-Version on every sync webhook. Composer gives
     * dev installs no usable version, so it lives here; bump it with every
     * release, together with the CHANGELOG.
     */
    public const VERSION = '1.11.0';

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!isset($this->extension)) {
            $this->extension = new DependencyInjection\EmporiqaExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
