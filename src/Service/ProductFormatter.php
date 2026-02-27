<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class ProductFormatter implements ProductFormatterInterface
{
    use TranslationHelperTrait;

    private const AVAILABILITY_AVAILABLE = 'available';
    private const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';

    public function __construct(
        private RouterInterface $router,
        private array $enabledLanguages = [],
        private array $channelMapping = [],
        private string $baseUrl = '',
        private ?LoggerInterface $logger = null,
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

                $lang = $this->getShortLanguageCode($locale);

                $names[$empChannelKey][$lang] = $translation->getName();
                $descriptions[$empChannelKey][$lang] = $translation->getDescription();
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $attributes[$empChannelKey][$lang] = $this->getProductAttributes($product, $locale);
            }

            $categories[$empChannelKey] = $this->getCategoryNames($product, $channelLocales);
            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $variant);
            $availabilityStatuses[$empChannelKey] = $this->getAvailabilityStatus($product, $variant);
            $stockQuantities[$empChannelKey] = $this->getStockQuantity($variant);
            $images[$empChannelKey] = $this->getProductImages($product);
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
                'images' => $images,
                'attributes' => $attributes,
                'parent_sku' => null,
                'is_parent' => false,
                'variation_attributes' => [],
            ],
        ];
    }

    private function formatParentProduct(ProductInterface $product): array
    {
        $defaultVariant = $product->getVariants()->first() ?: null;
        $variationAttributes = $this->getVariationAttributes($product);

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

                $lang = $this->getShortLanguageCode($locale);

                $names[$empChannelKey][$lang] = $translation->getName();
                $descriptions[$empChannelKey][$lang] = $translation->getDescription();
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $attributes[$empChannelKey][$lang] = $this->getProductAttributes($product, $locale);
            }

            $categories[$empChannelKey] = $this->getCategoryNames($product, $channelLocales);
            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $defaultVariant);
            $availabilityStatuses[$empChannelKey] = $this->getAvailabilityStatus($product);
            $stockQuantities[$empChannelKey] = null;
            $images[$empChannelKey] = $this->getProductImages($product);
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

        foreach ($product->getChannels() as $channel) {
            $empChannelKey = $this->resolveChannelKey($channel);
            if (!in_array($empChannelKey, $channelKeys, true)) {
                $channelKeys[] = $empChannelKey;
            }

            $channelLocales = $this->getChannelLocales($channel);

            foreach ($channelLocales as $locale) {
                $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
                $lang = $this->getShortLanguageCode($locale);

                $variantOptionAttrs = $this->getVariantOptionAttributes($variant, $locale);
                $baseName = $translation?->getName() ?? $variant->getCode();
                $variantName = $baseName;
                if (!empty($variantOptionAttrs)) {
                    $variantName .= ' - ' . implode(' / ', $variantOptionAttrs);
                }

                $names[$empChannelKey][$lang] = $variantName;
                $descriptions[$empChannelKey][$lang] = $translation?->getDescription();
                $links[$empChannelKey][$lang] = $this->generateProductUrl($product, $locale);
                $attributes[$empChannelKey][$lang] = array_merge(
                    $this->getProductAttributes($product, $locale),
                    $variantOptionAttrs,
                );
            }

            $categories[$empChannelKey] = $this->getCategoryNames($product, $channelLocales);
            $brands[$empChannelKey] = $this->getBrandValue($product) ?? '';
            $prices[$empChannelKey] = $this->getChannelPrices($channel, $variant);
            $availabilityStatuses[$empChannelKey] = $this->getAvailabilityStatus($product, $variant);
            $stockQuantities[$empChannelKey] = $this->getStockQuantity($variant);
            $images[$empChannelKey] = $this->getProductImages($product);
        }

        return [
            'type' => 'product.updated',
            'data' => [
                'identification_number' => 'variation-' . $variant->getId(),
                'sku' => $variant->getCode(),
                'channels' => $channelKeys,
                'names' => $names,
                'descriptions' => $descriptions,
                'links' => $links,
                'categories' => $categories,
                'brands' => $brands,
                'prices' => $prices,
                'availability_statuses' => $availabilityStatuses,
                'stock_quantities' => $stockQuantities,
                'images' => $images,
                'attributes' => $attributes,
                'parent_sku' => $product->getCode(),
                'is_parent' => false,
                'variation_attributes' => [],
            ],
        ];
    }

    private function resolveChannelKey(ChannelInterface $channel): string
    {
        $code = $channel->getCode();

        return $this->channelMapping[$code] ?? '';
    }

    /**
     * Returns the enabled locales for a channel, intersected with enabled_languages.
     * @return string[]
     */
    private function getChannelLocales(ChannelInterface $channel): array
    {
        $channelLocales = [];
        foreach ($channel->getLocales() as $locale) {
            $channelLocales[] = $locale->getCode();
        }

        if (empty($channelLocales)) {
            return $this->enabledLanguages;
        }

        $intersected = array_intersect($this->enabledLanguages, $channelLocales);

        return !empty($intersected) ? array_values($intersected) : $this->enabledLanguages;
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

        $currentPrice = $channelPricing->getPrice() ? $channelPricing->getPrice() / 100 : null;
        $regularPrice = $channelPricing->getOriginalPrice() ? $channelPricing->getOriginalPrice() / 100 : null;
        if ($regularPrice === null && $currentPrice !== null) {
            $regularPrice = $currentPrice;
        }

        if ($currentPrice === null) {
            return [];
        }

        $currencyCode = $channel->getBaseCurrency()?->getCode() ?? 'EUR';

        return [
            [
                'currency' => $currencyCode,
                'current_price' => $currentPrice,
                'regular_price' => $regularPrice,
            ],
        ];
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

    private function getStockQuantity(?ProductVariantInterface $variant): ?int
    {
        if ($variant === null || !$variant->isTracked()) {
            return null;
        }

        return max(0, $variant->getOnHand() - $variant->getOnHold());
    }

    private function getCategoryNames(ProductInterface $product, array $locales): array
    {
        $taxon = $product->getMainTaxon();
        if ($taxon === null) {
            return [];
        }

        $primaryLocale = $locales[0] ?? 'en';
        $name = $taxon->getTranslation($primaryLocale)?->getName();

        return $name ? [$name] : [];
    }

    private function getBrandValue(ProductInterface $product): ?string
    {
        foreach ($product->getAttributes() as $attributeValue) {
            if (strtolower($attributeValue->getAttribute()?->getCode() ?? '') === 'brand') {
                return $attributeValue->getValue();
            }
        }

        return null;
    }

    private function getProductImages(ProductInterface $product): array
    {
        $images = [];
        foreach ($product->getImages() as $image) {
            $images[] = $this->generateImageUrl($image->getPath());
        }

        return $images;
    }

    private function getVariantOptionAttributes(ProductVariantInterface $variant, string $locale): array
    {
        $attrs = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $optionName = $optionValue->getOption()?->getTranslation($locale)?->getName()
                ?? $optionValue->getOption()?->getCode();
            $attrs[$optionName] = $optionValue->getTranslation($locale)?->getValue()
                ?? $optionValue->getCode();
        }

        return $attrs;
    }

    private function getVariationAttributes(ProductInterface $product): array
    {
        $attributes = [];
        foreach ($product->getOptions() as $option) {
            $attributes[] = $option->getCode();
        }

        return $attributes;
    }

    private function getProductAttributes(ProductInterface $product, string $locale): array
    {
        $attributes = [];
        foreach ($product->getAttributes() as $attributeValue) {
            $attribute = $attributeValue->getAttribute();
            if ($attribute) {
                $name = $attribute->getTranslation($locale)?->getName() ?? $attribute->getCode();
                $attributes[$name] = $attributeValue->getValue();
            }
        }

        return $attributes;
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

        return $base . '/media/image/' . ltrim($path, '/');
    }
}
