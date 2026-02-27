<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductFormatterInterface
{
    public function format(ProductInterface $product): array;

    public function formatForDeletion(ProductInterface $product): array;

    public function formatVariantForDeletion(ProductVariantInterface $variant, ProductInterface $product): array;
}
