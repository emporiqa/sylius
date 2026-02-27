<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;

interface PageFormatterInterface
{
    public function format(PageInterface $page): array;

    public function formatForDeletion(PageInterface $page): array;
}
