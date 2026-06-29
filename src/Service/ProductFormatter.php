<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Event\MinOrderQuantityEvent;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductFormatter implements ProductFormatterInterface
{
    use TranslationHelperTrait;

    private const AVAILABILITY_AVAILABLE = 'available';
    private const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    public function __construct(
        private RouterInterface $router,
        private ChannelMappingResolver $channelMappingResolver,
        private array $enabledLanguages = [],
        private string $baseUrl = '',
        private string $brandAttributeCode = 'brand',
        private string $mediaBasePath = '/media/image/',
        private ?LoggerInterface $logger = null,
        private string $minOrderQuantityAttribute = 'min_order_qty',
        private ?EventDispatcherInterface $eventDispatcher = null,
        private string $maxOrderQuantityAttribute = 'max_order_qty',
        private string $conditionAttribute = 'condition',
        private string $virtualAttribute = 'virtual',
    ) {}

    public function format(ProductInterface $product): array
    {
        $channels = $product->getChannels();
        if ($channels->isEmpty()) {
            $this->logger?->debug('Product has no channels, skipping', [
                'product_id' => $product->getId(),
            ]);
            return [];
        }

        $variants = $product->getVariants();
        $hasVariants = $variants->count() > 1;

        if ($hasVariants) {
            $events = [];
            $events[] = $this->formatParentProduct($product);

            foreach ($variants as $variant) {
                $events[] = $this->formatVariant($variant, $product);
            }

            return $events;
        }

        return [$this->formatSimpleProduct($product)];
    }

    public function formatForDeletion(ProductInterface $product): array
    {
        $events = [
            [
                'type' => 'product.deleted',
                'data' => [
                    'identification_number' => 'product-' . $product->getId(),
                ],
            ],
        ];

        foreach ($product->getVariants() as $variant) {
            $events[] = [
                'type' => 'product.deleted',
                'data' => [
                    'identification_number' => 'variation-' . $variant->getId(),
                ],
            ];
        }

        return $events;
    }

    public function formatVariantForDeletion(ProductVariantInterface $variant, ProductInterface $product): array
    {
        return [
            [
                'type' => 'product.deleted',
                'data' => [
                    'identification_number' => 'variation-' . $variant->getId(),
                ],
            ],
        ];
    }

    private function formatSimpleProduct(ProductInterface $product): array
    {
        $variant = $product->getVariants()->first() ?: null;

        $channelKeys = [];
        $names = [];
        $descriptions = [];
        $links = [];
        $categories = [];
        $brands = [];
        $prices = [];
        $availabilityStatuses = [];
        $stockQuantities = [];
        $images = [];
        $attributes = [];
        $minOrderQuantities = [];
        $maxOrderQuantities = [];

        foreach ($product->getChannels() as $channel) {
            $empChannelKey = $this->resolveChannelKey($channel);
            if (!in_array($empChannelKey, $channelKeys, true)) {
                $channelKeys[] = $empChannelKey;
            }

            $channelLocales = $this->getChannelLocales($channel);

            foreach ($channelLocales as $locale) {
                $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
                if (!$translation || !$translation->getName()) {
                    continue;
                }

                $lang = $locale;

                $names[$empChannelKey][$lang] = $translation->getName();
                $descriptions[$empChannelKey][$lang] = strip_tags($translation->getDescription() ?? '');
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $categories[$empChannelKey][$lang] = $this->getCategoryNamesForLocale($product, $locale);
                $attributes[$empChannelKey][$lang] = $this->getProductAttributes($product, $locale) ?: new \stdClass();
            }

            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $variant);
            $availabilityStatuses[$empChannelKey] = $this->getAvailabilityStatus($product, $variant);
            $stockQuantities[$empChannelKey] = $this->getStockQuantity($variant);
            $images[$empChannelKey] = $this->getProductImages($product);
            $minOrderQuantities[$empChannelKey] = $this->getMinOrderQuantity($product, $empChannelKey);
            $maxOrderQuantities[$empChannelKey] = $this->getMaxOrderQuantity($product, $empChannelKey);
        }

        return [
            'type' => 'product.updated',
            'data' => [
                'identification_number' => 'product-' . $product->getId(),
                'sku' => $variant?->getCode() ?? $product->getCode() ?? '',
                'channels' => $channelKeys,
                'names' => $names,
                'descriptions' => $descriptions,
                'links' => $links,
                'categories' => $categories,
                'brands' => $brands,
                'prices' => $prices,
                'availability_statuses' => $availabilityStatuses,
                'stock_quantities' => $stockQuantities,
                'min_order_quantities' => $minOrderQuantities,
                'max_order_quantities' => $maxOrderQuantities,
                'available_for_order' => $this->isAvailableForOrder($product),
                'condition' => $this->getProductCondition($product),
                'is_virtual' => $this->isVirtual($product),
                'images' => $images,
                'attributes' => $attributes,
                'parent_sku' => null,
                'is_parent' => false,
                'variation_attributes' => new \stdClass(),
            ],
        ];
    }

    private function formatParentProduct(ProductInterface $product): array
    {
        $defaultVariant = $product->getVariants()->first() ?: null;

        $channelKeys = [];
        $names = [];
        $descriptions = [];
        $links = [];
        $categories = [];
        $brands = [];
        $prices = [];
        $availabilityStatuses = [];
        $stockQuantities = [];
        $images = [];
        $attributes = [];
        $variationAttributes = [];
        $minOrderQuantities = [];
        $maxOrderQuantities = [];

        foreach ($product->getChannels() as $channel) {
            $empChannelKey = $this->resolveChannelKey($channel);
            if (!in_array($empChannelKey, $channelKeys, true)) {
                $channelKeys[] = $empChannelKey;
            }

            $channelLocales = $this->getChannelLocales($channel);

            foreach ($channelLocales as $locale) {
                $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
                if (!$translation || !$translation->getName()) {
                    continue;
                }

                $lang = $locale;

                $names[$empChannelKey][$lang] = $translation->getName();
                $descriptions[$empChannelKey][$lang] = strip_tags($translation->getDescription() ?? '');
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $categories[$empChannelKey][$lang] = $this->getCategoryNamesForLocale($product, $locale);
                $attributes[$empChannelKey][$lang] = $this->getProductAttributes($product, $locale) ?: new \stdClass();
                $variationAttributes[$empChannelKey][$lang] = $this->getVariationAttributeNames($product, $locale);
            }

            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $defaultVariant);
            $availabilityStatuses[$empChannelKey] = $this->getParentAvailabilityStatus($product);
            $stockQuantities[$empChannelKey] = null;
            $images[$empChannelKey] = $this->getProductImages($product);
            // Parent reflects the strictest constraint across its variants —
            // mirrors the WooCommerce variable-product pattern. Variant-level
            // overrides come through formatVariant().
            $minOrderQuantities[$empChannelKey] = $this->getMaxVariantMinOrderQuantity($product, $empChannelKey);
            // Max-per-order is sourced from a product-level attribute, so it is
            // identical across variants — read it once for the parent.
            $maxOrderQuantities[$empChannelKey] = $this->getMaxOrderQuantity($product, $empChannelKey);
        }

        return [
            'type' => 'product.updated',
            'data' => [
                'identification_number' => 'product-' . $product->getId(),
                'sku' => $product->getCode() ?? '',
                'channels' => $channelKeys,
                'names' => $names,
                'descriptions' => $descriptions,
                'links' => $links,
                'categories' => $categories,
                'brands' => $brands,
                'prices' => $prices,
                'availability_statuses' => $availabilityStatuses,
                'stock_quantities' => $stockQuantities,
                'min_order_quantities' => $minOrderQuantities,
                'max_order_quantities' => $maxOrderQuantities,
                'available_for_order' => $this->isAvailableForOrder($product),
                'condition' => $this->getProductCondition($product),
                'is_virtual' => $this->isVirtual($product),
                'images' => $images,
                'attributes' => $attributes,
                'parent_sku' => null,
                'is_parent' => true,
                'variation_attributes' => $variationAttributes,
            ],
        ];
    }

    private function formatVariant(ProductVariantInterface $variant, ProductInterface $product): array
    {
        $channelKeys = [];
        $names = [];
        $descriptions = [];
        $links = [];
        $categories = [];
        $brands = [];
        $prices = [];
        $availabilityStatuses = [];
        $stockQuantities = [];
        $images = [];
        $attributes = [];
        $minOrderQuantities = [];
        $maxOrderQuantities = [];

        foreach ($product->getChannels() as $channel) {
            $empChannelKey = $this->resolveChannelKey($channel);
            if (!in_array($empChannelKey, $channelKeys, true)) {
                $channelKeys[] = $empChannelKey;
            }

            $channelLocales = $this->getChannelLocales($channel);

            foreach ($channelLocales as $locale) {
                $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
                $lang = $locale;

                $variantOptionAttrs = $this->getVariantOptionAttributes($variant, $locale);
                $baseName = $translation?->getName() ?? $variant->getCode() ?? '';
                $variantName = $baseName;
                if (!empty($variantOptionAttrs)) {
                    $variantName .= ' - ' . implode(' / ', $variantOptionAttrs);
                }

                $names[$empChannelKey][$lang] = $variantName;
                $descriptions[$empChannelKey][$lang] = strip_tags($translation?->getDescription() ?? '');
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $categories[$empChannelKey][$lang] = $this->getCategoryNamesForLocale($product, $locale);
                $mergedAttrs = array_merge(
                    $this->getProductAttributes($product, $locale),
                    $variantOptionAttrs,
                );
                $attributes[$empChannelKey][$lang] = $mergedAttrs ?: new \stdClass();
            }

            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $variant);
            $availabilityStatuses[$empChannelKey] = $this->getAvailabilityStatus($product, $variant);
            $stockQuantities[$empChannelKey] = $this->getStockQuantity($variant);
            $images[$empChannelKey] = $this->getVariantImages($variant, $product);
            $minOrderQuantities[$empChannelKey] = $this->getMinOrderQuantity($variant, $empChannelKey);
            $maxOrderQuantities[$empChannelKey] = $this->getMaxOrderQuantity($variant, $empChannelKey);
        }

        return [
            'type' => 'product.updated',
            'data' => [
                'identification_number' => 'variation-' . $variant->getId(),
                'sku' => $variant->getCode() ?? '',
                'channels' => $channelKeys,
                'names' => $names,
                'descriptions' => $descriptions,
                'links' => $links,
                'categories' => $categories,
                'brands' => $brands,
                'prices' => $prices,
                'availability_statuses' => $availabilityStatuses,
                'stock_quantities' => $stockQuantities,
                'min_order_quantities' => $minOrderQuantities,
                'max_order_quantities' => $maxOrderQuantities,
                'available_for_order' => $this->isAvailableForOrder($product),
                'condition' => $this->getProductCondition($product),
                'is_virtual' => $this->isVirtual($product),
                'images' => $images,
                'attributes' => $attributes,
                'parent_sku' => $product->getCode() ?? '',
                'is_parent' => false,
                'variation_attributes' => new \stdClass(),
            ],
        ];
    }

    private function resolveChannelKey(ChannelInterface $channel): string
    {
        return $this->channelMappingResolver->resolveKey($channel);
    }

    /**
     * Returns the enabled locales for a channel, intersected with enabled_languages.
     * @return string[]
     */
    private function getChannelLocales(ChannelInterface $channel): array
    {
        $channelLocales = [];
        foreach ($channel->getLocales() as $locale) {
            $code = $locale->getCode();
            if ($code !== null) {
                $channelLocales[] = $code;
            }
        }

        if (empty($channelLocales)) {
            return $this->enabledLanguages;
        }

        $intersected = array_intersect($this->enabledLanguages, $channelLocales);

        if (empty($intersected)) {
            $this->logger?->warning('No overlap between enabled_languages and channel locales', [
                'enabled_languages' => $this->enabledLanguages,
                'channel_locales' => $channelLocales,
            ]);

            return [];
        }

        return array_values($intersected);
    }

    private function getChannelPrices(ChannelInterface $channel, ?ProductVariantInterface $variant): array
    {
        if ($variant === null) {
            return [];
        }

        $channelPricing = $variant->getChannelPricingForChannel($channel);
        if ($channelPricing === null) {
            return [];
        }

        $currencyCode = $channel->getBaseCurrency()?->getCode() ?? '';

        $currentPrice = $channelPricing->getPrice() !== null
            ? CurrencyHelper::toCurrencyUnits($channelPricing->getPrice(), $currencyCode)
            : null;
        $regularPrice = $channelPricing->getOriginalPrice() !== null
            ? CurrencyHelper::toCurrencyUnits($channelPricing->getOriginalPrice(), $currencyCode)
            : null;
        if ($regularPrice === null && $currentPrice !== null) {
            $regularPrice = $currentPrice;
        }

        if ($currentPrice === null) {
            return [];
        }

        $priceData = [
            'currency' => $currencyCode,
            'current_price' => $currentPrice,
            'regular_price' => $regularPrice,
        ];

        if (method_exists($channelPricing, 'getMinimumPrice') && $channelPricing->getMinimumPrice() !== null) {
            $priceData['minimum_price'] = CurrencyHelper::toCurrencyUnits($channelPricing->getMinimumPrice(), $currencyCode);
        }

        return [$priceData];
    }

    private function getAvailabilityStatus(ProductInterface $product, ?ProductVariantInterface $variant = null): string
    {
        if (!$product->isEnabled()) {
            return self::AVAILABILITY_OUT_OF_STOCK;
        }

        if ($variant !== null) {
            if (!$variant->isEnabled()) {
                return self::AVAILABILITY_OUT_OF_STOCK;
            }

            if ($variant->isTracked() && ($variant->getOnHand() - $variant->getOnHold()) <= 0) {
                return self::AVAILABILITY_OUT_OF_STOCK;
            }
        }

        return self::AVAILABILITY_AVAILABLE;
    }

    /**
     * Aggregate availability for a configurable product's parent payload.
     *
     * `getAvailabilityStatus($product)` called with no variant only checks the
     * product's enabled flag, so a product whose variants are all out of stock
     * would report the parent as available. The Emporiqa backend trusts the
     * parent point's stored availability when filtering search results
     * (products_search_service: `VariationGroup.parent.is_available`), so a
     * wrong parent value surfaces sold-out products. Aggregate instead: the
     * parent is available when at least one variant is available, otherwise
     * out of stock — matching the WooCommerce / Drupal / Magento / PrestaShop
     * formatters. Sylius has no backorder state, so this stays binary.
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

    private function getStockQuantity(?ProductVariantInterface $variant): ?int
    {
        if ($variant === null || !$variant->isTracked()) {
            return null;
        }

        return max(0, $variant->getOnHand() - $variant->getOnHold());
    }

    private function getCategoryNamesForLocale(ProductInterface $product, string $locale): array
    {
        $categories = [];

        $mainTaxon = $product->getMainTaxon();
        if ($mainTaxon !== null) {
            $path = $this->buildTaxonPath($mainTaxon, $locale);
            if ($path !== '') {
                $categories[] = $path;
            }
        }

        if (method_exists($product, 'getProductTaxons')) {
            foreach ($product->getProductTaxons() as $productTaxon) {
                $taxon = $productTaxon->getTaxon();
                if ($taxon === null || $taxon === $mainTaxon) {
                    continue;
                }
                $path = $this->buildTaxonPath($taxon, $locale);
                if ($path !== '' && !in_array($path, $categories, true)) {
                    $categories[] = $path;
                }
            }
        }

        return $categories;
    }

    private function buildTaxonPath(TaxonInterface $taxon, string $locale): string
    {
        $ancestors = [];
        $current = $taxon;

        $depth = 0;
        while ($current !== null && $depth++ < 50) {
            if ($current->isRoot() && $current !== $taxon) {
                break;
            }
            try {
                $name = $current->getTranslation($locale)?->getName();
            } catch (\Exception) {
                $name = null;
            }
            if ($name) {
                $ancestors[] = $name;
            }
            $current = $current->getParent();
        }

        return implode(' > ', array_reverse($ancestors));
    }

    private function getBrandValue(ProductInterface $product): ?string
    {
        $targetCode = strtolower($this->brandAttributeCode);

        foreach ($product->getAttributes() as $attributeValue) {
            if (strtolower($attributeValue->getAttribute()?->getCode() ?? '') === $targetCode) {
                $value = $attributeValue->getValue();

                if (is_string($value)) {
                    return $value;
                }

                if (is_array($value)) {
                    return implode(', ', array_filter($value, 'is_string'));
                }

                if (is_scalar($value)) {
                    return (string) $value;
                }

                return null;
            }
        }

        return null;
    }

    private function getProductImages(ProductInterface $product): array
    {
        $images = [];
        foreach ($product->getImages() as $image) {
            $url = $this->generateImageUrl($image->getPath());
            if ($url !== '') {
                $images[] = $url;
            }
        }

        return $images;
    }

    /**
     * Returns the images associated with a specific variant.
     *
     * Sylius attaches images to the product and optionally links each one to
     * specific variants. When a variant has its own linked images, use only
     * those; otherwise fall back to the full product gallery so a variant is
     * never left without an image.
     */
    private function getVariantImages(ProductVariantInterface $variant, ProductInterface $product): array
    {
        $variantImages = [];
        foreach ($product->getImages() as $image) {
            if ($image instanceof ProductImageInterface && $image->hasProductVariant($variant)) {
                $url = $this->generateImageUrl($image->getPath());
                if ($url !== '') {
                    $variantImages[] = $url;
                }
            }
        }

        return !empty($variantImages) ? $variantImages : $this->getProductImages($product);
    }

    private function getVariantOptionAttributes(ProductVariantInterface $variant, string $locale): array
    {
        $attrs = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            try {
                $optionName = $optionValue->getOption()?->getTranslation($locale)?->getName()
                    ?? $optionValue->getOption()?->getCode();
            } catch (\Exception) {
                $optionName = $optionValue->getOption()?->getCode();
            }
            try {
                $attrs[$optionName] = $optionValue->getTranslation($locale)?->getValue()
                    ?? $optionValue->getCode();
            } catch (\Exception) {
                $attrs[$optionName] = $optionValue->getCode();
            }
        }

        return $attrs;
    }

    private function getVariationAttributeNames(ProductInterface $product, string $locale): array
    {
        $attributes = [];
        foreach ($product->getOptions() as $option) {
            try {
                $attributes[] = $option->getTranslation($locale)?->getName()
                    ?? $option->getCode();
            } catch (\Exception) {
                $attributes[] = $option->getCode();
            }
        }

        return $attributes;
    }

    private function getProductAttributes(ProductInterface $product, string $locale): array
    {
        $attributes = [];
        foreach ($product->getAttributes() as $attributeValue) {
            $attribute = $attributeValue->getAttribute();
            if ($attribute) {
                try {
                    $name = $attribute->getTranslation($locale)?->getName() ?? $attribute->getCode();
                } catch (\Exception) {
                    $name = $attribute->getCode();
                }
                $attributes[$name] = $attributeValue->getValue();
            }
        }

        return $attributes;
    }

    /**
     * Resolve the min-order-quantity for a product / variant on a given
     * channel. Sylius core has no native field for this, so we source it from
     * the configured product attribute (default: `min_order_qty`). For
     * variants we read the parent product's attribute — Sylius doesn't model
     * per-variant attributes here. Listeners on MinOrderQuantityEvent can
     * override the resolved value (e.g. B2B per-customer rules).
     *
     * @param ProductInterface|ProductVariantInterface $entity
     */
    private function getMinOrderQuantity(object $entity, string $channelKey): int
    {
        if ($entity instanceof ProductVariantInterface) {
            $product = $entity->getProduct();
        } elseif ($entity instanceof ProductInterface) {
            $product = $entity;
        } else {
            return 1;
        }

        $quantity = 1;
        if ($product !== null) {
            $resolved = $this->readMinOrderQuantityFromAttributes($product);
            if ($resolved !== null) {
                $quantity = $resolved;
            }
        }

        if ($this->eventDispatcher !== null) {
            $event = new MinOrderQuantityEvent($quantity, $entity, $channelKey);
            $this->eventDispatcher->dispatch($event, MinOrderQuantityEvent::NAME);
            $quantity = $event->getQuantity();
        }

        return max(1, $quantity);
    }

    private function getMaxVariantMinOrderQuantity(ProductInterface $product, string $channelKey): int
    {
        $max = 1;
        foreach ($product->getVariants() as $variant) {
            $value = $this->getMinOrderQuantity($variant, $channelKey);
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    private function readMinOrderQuantityFromAttributes(ProductInterface $product): ?int
    {
        $targetCode = strtolower($this->minOrderQuantityAttribute);

        foreach ($product->getAttributes() as $attributeValue) {
            $code = strtolower($attributeValue->getAttribute()?->getCode() ?? '');
            if ($code !== $targetCode) {
                continue;
            }

            $value = $attributeValue->getValue();

            if (is_int($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_string($value) && $value !== '' && is_numeric(trim($value))) {
                return (int) trim($value);
            }

            return null;
        }

        return null;
    }

    /**
     * Resolve the max-order-quantity for a product / variant on a given
     * channel. Sylius core has no native field for this, so we source it from
     * the configured product attribute (default: `max_order_qty`), mirroring
     * the min-order-quantity mechanism. Returns null when the attribute is
     * absent — null means "no upper limit". For variants we read the parent
     * product's attribute, since Sylius doesn't model per-variant attributes
     * here.
     *
     * @param ProductInterface|ProductVariantInterface $entity
     */
    private function getMaxOrderQuantity(object $entity, string $channelKey): ?int
    {
        if ($entity instanceof ProductVariantInterface) {
            $product = $entity->getProduct();
        } elseif ($entity instanceof ProductInterface) {
            $product = $entity;
        } else {
            return null;
        }

        if ($product === null) {
            return null;
        }

        return $this->readMaxOrderQuantityFromAttributes($product);
    }

    private function readMaxOrderQuantityFromAttributes(ProductInterface $product): ?int
    {
        $targetCode = strtolower($this->maxOrderQuantityAttribute);

        foreach ($product->getAttributes() as $attributeValue) {
            $code = strtolower($attributeValue->getAttribute()?->getCode() ?? '');
            if ($code !== $targetCode) {
                continue;
            }

            $value = $attributeValue->getValue();

            // A non-positive cap is meaningless (0 would make the product
            // un-orderable); treat it as "no limit" to match the WooCommerce
            // contract.
            if (is_int($value)) {
                return $value > 0 ? $value : null;
            }
            if (is_numeric($value)) {
                $int = (int) $value;

                return $int > 0 ? $int : null;
            }
            if (is_string($value) && $value !== '' && is_numeric(trim($value))) {
                $int = (int) trim($value);

                return $int > 0 ? $int : null;
            }

            return null;
        }

        return null;
    }

    /**
     * Whether the product can currently be ordered. Sylius has no dedicated
     * field, so we derive it from the enabled flag.
     */
    private function isAvailableForOrder(ProductInterface $product): bool
    {
        return $product->isEnabled();
    }

    /**
     * Product condition (e.g. "new", "used", "refurbished"). Sylius has no
     * native field; read from the configured product attribute (default:
     * `condition`), returning null when absent.
     */
    private function getProductCondition(ProductInterface $product): ?string
    {
        $targetCode = strtolower($this->conditionAttribute);

        foreach ($product->getAttributes() as $attributeValue) {
            if (strtolower($attributeValue->getAttribute()?->getCode() ?? '') !== $targetCode) {
                continue;
            }

            $value = $attributeValue->getValue();

            if (is_string($value)) {
                return $value !== '' ? $value : null;
            }
            if (is_array($value)) {
                $joined = implode(', ', array_filter($value, 'is_string'));

                return $joined !== '' ? $joined : null;
            }
            if (is_scalar($value)) {
                return (string) $value;
            }

            return null;
        }

        return null;
    }

    /**
     * Whether the product is virtual / non-shippable. Sylius has no native
     * virtual flag; read from the configured product attribute (default:
     * `virtual`), defaulting to false when absent.
     */
    private function isVirtual(ProductInterface $product): bool
    {
        $targetCode = strtolower($this->virtualAttribute);

        foreach ($product->getAttributes() as $attributeValue) {
            if (strtolower($attributeValue->getAttribute()?->getCode() ?? '') !== $targetCode) {
                continue;
            }

            $value = $attributeValue->getValue();

            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
            }
            if (is_numeric($value)) {
                return (int) $value === 1;
            }

            return false;
        }

        return false;
    }

    private function generateProductUrl(ProductInterface $product, string $locale): string
    {
        $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
        $slug = $translation?->getSlug();
        if (!$slug) {
            return '';
        }

        try {
            return $this->router->generate(
                'sylius_shop_product_show',
                ['slug' => $slug, '_locale' => $locale],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to generate product URL', [
                'product_id' => $product->getId(),
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function generateImageUrl(string $path): string
    {
        if (empty($path)) {
            return '';
        }

        $context = $this->router->getContext();
        $host = $context->getHost();
        if ($host) {
            $scheme = $context->getScheme() ?: 'https';
            $base = $scheme . '://' . $host;
        } else {
            $base = rtrim($this->baseUrl, '/');
        }

        $mediaBase = rtrim($this->mediaBasePath, '/');

        return $base . $mediaBase . '/' . ltrim($path, '/');
    }
}
