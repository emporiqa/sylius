<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

interface ProductFormatterInterface
{
    public function format(ProductInterface $product): array;

    public function formatForLanguage(ProductInterface $product, string $locale): array;

    public function formatVariant(
        ProductVariantInterface $variant,
        ProductInterface $product,
        string $locale,
        ?string $category = null,
        ?string $brand = null,
    ): array;

    public function formatForDeletion(ProductInterface $product): array;

    public function formatVariantForDeletion(ProductVariantInterface $variant, ProductInterface $product): array;
}
