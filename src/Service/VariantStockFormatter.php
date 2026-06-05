<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Builds the lightweight `product.availability` event for inventory-only
 * changes. It reuses the exact availability/stock semantics of
 * {@see ProductFormatter::getAvailabilityStatus()} and
 * {@see ProductFormatter::getStockQuantity()} but never rebuilds the full
 * product payload (names, prices, images, attributes, …), so an order-driven
 * stock decrement sends a few bytes instead of the whole product.
 *
 * The per-channel keys and the status mapping mirror the full event so the
 * Emporiqa side can apply both through the same code path.
 */
class VariantStockFormatter implements VariantStockFormatterInterface
{
    private const AVAILABILITY_AVAILABLE = 'available';
    private const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    public function __construct(
        private ChannelMappingResolver $channelMappingResolver,
    ) {}

    public function format(ProductVariantInterface $variant): array
    {
        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface) {
            return [];
        }

        $channels = $product->getChannels();
        if ($channels->isEmpty()) {
            return [];
        }

        $availabilityStatuses = [];
        $stockQuantities = [];

        foreach ($channels as $channel) {
            $channelKey = $this->channelMappingResolver->resolveKey($channel);

            $availabilityStatuses[$channelKey] = $this->getAvailabilityStatus($product, $variant);
            $stockQuantities[$channelKey] = $this->getStockQuantity($variant);
        }

        if (empty($availabilityStatuses)) {
            return [];
        }

        $events = [];
        $events[] = [
            'type' => 'product.availability',
            'data' => [
                'identification_number' => $this->resolveIdentificationNumber($variant, $product),
                'sku' => $variant->getCode() ?? '',
                'availability_statuses' => $availabilityStatuses,
                'stock_quantities' => $stockQuantities,
            ],
        ];

        // For a multi-variant product the event above targets the
        // `variation-{id}` point. The parent `product-{id}` point also stores
        // an aggregated availability that the Emporiqa search filter trusts,
        // and the backend never re-derives it — so we must refresh it here too,
        // or a sellout of the last in-stock variant would leave the parent
        // showing as available until the next full product save.
        if ($product->getVariants()->count() > 1) {
            $parentAvailability = [];
            $parentStock = [];
            $parentStatus = $this->getParentAvailabilityStatus($product);
            foreach ($channels as $channel) {
                $channelKey = $this->channelMappingResolver->resolveKey($channel);
                $parentAvailability[$channelKey] = $parentStatus;
                // Parent carries no own stock — matches ProductFormatter's
                // parent payload (stock_quantities = null per channel).
                $parentStock[$channelKey] = null;
            }

            $events[] = [
                'type' => 'product.availability',
                'data' => [
                    'identification_number' => 'product-' . $product->getId(),
                    'sku' => $product->getCode() ?? '',
                    'availability_statuses' => $parentAvailability,
                    'stock_quantities' => $parentStock,
                ],
            ];
        }

        return $events;
    }

    /**
     * Mirrors ProductFormatter: a single-variant product is emitted under
     * `product-{id}` (the simple-product identification number), while a
     * variant of a multi-variant product is emitted under `variation-{id}`.
     */
    public function resolveIdentificationNumber(ProductVariantInterface $variant, ProductInterface $product): string
    {
        $hasVariants = $product->getVariants()->count() > 1;

        if ($hasVariants) {
            return 'variation-' . $variant->getId();
        }

        return 'product-' . $product->getId();
    }

    /**
     * Identical semantics to ProductFormatter::getAvailabilityStatus().
     */
    private function getAvailabilityStatus(ProductInterface $product, ProductVariantInterface $variant): string
    {
        if (!$product->isEnabled()) {
            return self::AVAILABILITY_OUT_OF_STOCK;
        }

        if (!$variant->isEnabled()) {
            return self::AVAILABILITY_OUT_OF_STOCK;
        }

        if ($variant->isTracked() && ($variant->getOnHand() - $variant->getOnHold()) <= 0) {
            return self::AVAILABILITY_OUT_OF_STOCK;
        }

        return self::AVAILABILITY_AVAILABLE;
    }

    /**
     * Identical semantics to ProductFormatter::getStockQuantity().
     */
    private function getStockQuantity(ProductVariantInterface $variant): ?int
    {
        if (!$variant->isTracked()) {
            return null;
        }

        return max(0, $variant->getOnHand() - $variant->getOnHold());
    }

    /**
     * Aggregate parent availability across all variants — identical semantics
     * to ProductFormatter::getParentAvailabilityStatus(). Available when at
     * least one variant is available, otherwise out of stock.
     */
    private function getParentAvailabilityStatus(ProductInterface $product): string
    {
        if (!$product->isEnabled()) {
            return self::AVAILABILITY_OUT_OF_STOCK;
        }

        foreach ($product->getVariants() as $variant) {
            if ($this->getAvailabilityStatus($product, $variant) === self::AVAILABILITY_AVAILABLE) {
                return self::AVAILABILITY_AVAILABLE;
            }
        }

        return self::AVAILABILITY_OUT_OF_STOCK;
    }
}
