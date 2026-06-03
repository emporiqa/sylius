<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductVariantInterface;

interface VariantStockFormatterInterface
{
    /**
     * Builds a single lightweight `product.availability` event for a variant
     * when only its inventory changed. Returns null when the variant has no
     * syncable channels (nothing to send).
     *
     * @return array{type: string, data: array}|null
     */
    public function format(ProductVariantInterface $variant): ?array;
}
