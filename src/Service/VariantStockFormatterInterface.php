<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductVariantInterface;

interface VariantStockFormatterInterface
{
    /**
     * Builds the lightweight `product.availability` event(s) for a variant
     * when only its inventory changed.
     *
     * Returns a list of events:
     *  - a single-variant product yields one `product-{id}` event;
     *  - a variant of a multi-variant product yields its own
     *    `variation-{id}` event PLUS a `product-{id}` parent event carrying
     *    the re-aggregated parent availability, because the Emporiqa backend
     *    only updates the exact identification_number it receives and never
     *    re-derives the parent from its variations.
     *
     * Returns an empty array when the variant has no syncable channels
     * (nothing to send).
     *
     * @return list<array{type: string, data: array}>
     */
    public function format(ProductVariantInterface $variant): array;
}
