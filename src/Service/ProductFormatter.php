<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;
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
        private ?LoggerInterface $logger = null,
    ) {}

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

    public function format(ProductInterface $product): array
    {
        $events = [];

        foreach ($this->enabledLanguages as $locale) {
            $events = array_merge($events, $this->formatForLanguage($product, $locale));
        }

        return $events;
    }

    public function formatForLanguage(ProductInterface $product, string $locale): array
    {
        $events = [];

        $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);
        if (!$translation || !$translation->getName()) {
            $this->logger?->debug('Product translation missing for locale', [
                'product_id' => $product->getId(),
                'locale' => $locale,
            ]);
            return $events;
        }

        $channel = $product->getChannels()->first();

        $taxon = $product->getMainTaxon();
        $category = $taxon?->getTranslation($locale)?->getName();

        $brand = null;
        foreach ($product->getAttributes() as $attributeValue) {
            if (strtolower($attributeValue->getAttribute()?->getCode() ?? '') === 'brand') {
                $brand = $attributeValue->getValue();
                break;
            }
        }

        $images = [];
        foreach ($product->getImages() as $image) {
            $images[] = $this->generateImageUrl($image->getPath());
        }

        $variants = $product->getVariants();
        $hasVariants = $variants->count() > 1;

        $defaultVariant = $product->getVariants()->first();
        $defaultChannelPricing = $defaultVariant?->getChannelPricingForChannel($channel);
        $defaultRegularPrice = $defaultChannelPricing?->getOriginalPrice() ? $defaultChannelPricing->getOriginalPrice() / 100 : null;
        $defaultCurrentPrice = $defaultChannelPricing?->getPrice() ? $defaultChannelPricing->getPrice() / 100 : null;
        if ($defaultRegularPrice === null && $defaultCurrentPrice !== null) {
            $defaultRegularPrice = $defaultCurrentPrice;
        }

        if ($hasVariants) {
            $variationAttributes = $this->getVariationAttributes($product);

            $events[] = [
                'type' => 'product.updated',
                'data' => [
                    'identification_number' => 'product-' . $product->getId(),
                    'name' => $translation->getName(),
                    'sku' => $product->getCode(),
                    'link' => $this->generateProductUrl($product, $locale),
                    'category' => $category,
                    'brand' => $brand,
                    'regular_price' => $defaultRegularPrice,
                    'current_price' => $defaultCurrentPrice,
                    'description' => $translation->getDescription(),
                    'language' => $this->getShortLanguageCode($locale),
                    'availability_status' => $this->getAvailabilityStatus($product),
                    'stock_quantity' => null,
                    'attributes' => $this->getProductAttributes($product, $locale),
                    'images' => $images,
                    'parent_sku' => null,
                    'is_parent' => true,
                    'variation_attributes' => $variationAttributes,
                ],
            ];

            foreach ($variants as $variant) {
                $events[] = $this->formatVariant($variant, $product, $locale, $category, $brand);
            }
        } else {
            $variant = $variants->first();
            $channelPricing = $variant?->getChannelPricingForChannel($channel);
            $regularPrice = $channelPricing?->getOriginalPrice() ? $channelPricing->getOriginalPrice() / 100 : null;
            $currentPrice = $channelPricing?->getPrice() ? $channelPricing->getPrice() / 100 : null;
            if ($regularPrice === null && $currentPrice !== null) {
                $regularPrice = $currentPrice;
            }

            $events[] = [
                'type' => 'product.updated',
                'data' => [
                    'identification_number' => 'product-' . $product->getId(),
                    'name' => $translation->getName(),
                    'sku' => $variant?->getCode() ?? $product->getCode(),
                    'link' => $this->generateProductUrl($product, $locale),
                    'category' => $category,
                    'brand' => $brand,
                    'regular_price' => $regularPrice,
                    'current_price' => $currentPrice,
                    'description' => $translation->getDescription(),
                    'language' => $this->getShortLanguageCode($locale),
                    'availability_status' => $this->getAvailabilityStatus($product, $variant),
                    'stock_quantity' => $this->getStockQuantity($variant),
                    'attributes' => $this->getProductAttributes($product, $locale),
                    'images' => $images,
                    'parent_sku' => null,
                    'is_parent' => false,
                    'variation_attributes' => [],
                ],
            ];
        }

        return $events;
    }

    public function formatVariant(
        ProductVariantInterface $variant,
        ProductInterface $product,
        string $locale,
        ?string $category = null,
        ?string $brand = null,
    ): array {
        $channel = $product->getChannels()->first();
        $channelPricing = $variant->getChannelPricingForChannel($channel);
        $translation = $this->findTranslationForLocale($product->getTranslations(), $locale);

        $variantAttributes = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $optionName = $optionValue->getOption()?->getTranslation($locale)?->getName()
                ?? $optionValue->getOption()?->getCode();
            $variantAttributes[$optionName] = $optionValue->getTranslation($locale)?->getValue()
                ?? $optionValue->getCode();
        }

        $images = [];
        foreach ($product->getImages() as $image) {
            $images[] = $this->generateImageUrl($image->getPath());
        }

        $regularPrice = $channelPricing?->getOriginalPrice() ? $channelPricing->getOriginalPrice() / 100 : null;
        $currentPrice = $channelPricing?->getPrice() ? $channelPricing->getPrice() / 100 : null;
        if ($regularPrice === null && $currentPrice !== null) {
            $regularPrice = $currentPrice;
        }

        $variantName = $translation?->getName() ?? $variant->getCode();
        if (!empty($variantAttributes)) {
            $variantName .= ' - ' . implode(' / ', $variantAttributes);
        }

        return [
            'type' => 'product.updated',
            'data' => [
                'identification_number' => 'variation-' . $variant->getId(),
                'name' => $variantName,
                'sku' => $variant->getCode(),
                'link' => $this->generateProductUrl($product, $locale),
                'category' => $category,
                'brand' => $brand,
                'regular_price' => $regularPrice,
                'current_price' => $currentPrice,
                'description' => $translation?->getDescription(),
                'language' => $this->getShortLanguageCode($locale),
                'availability_status' => $this->getAvailabilityStatus($product, $variant),
                'stock_quantity' => $this->getStockQuantity($variant),
                'attributes' => array_merge(
                    $this->getProductAttributes($product, $locale),
                    $variantAttributes
                ),
                'images' => $images,
                'parent_sku' => $product->getCode(),
                'is_parent' => false,
                'variation_attributes' => [],
            ],
        ];
    }

    public function formatForDeletion(ProductInterface $product): array
    {
        $events = [];

        foreach ($this->enabledLanguages as $locale) {
            $language = $this->getShortLanguageCode($locale);
            $events[] = [
                'type' => 'product.deleted',
                'data' => [
                    'identification_number' => 'product-' . $product->getId(),
                    'language' => $language,
                ],
            ];

            foreach ($product->getVariants() as $variant) {
                $events[] = [
                    'type' => 'product.deleted',
                    'data' => [
                        'identification_number' => 'variation-' . $variant->getId(),
                        'language' => $language,
                    ],
                ];
            }
        }

        return $events;
    }

    public function formatVariantForDeletion(ProductVariantInterface $variant, ProductInterface $product): array
    {
        $events = [];

        foreach ($this->enabledLanguages as $locale) {
            $events[] = [
                'type' => 'product.deleted',
                'data' => [
                    'identification_number' => 'variation-' . $variant->getId(),
                    'language' => $this->getShortLanguageCode($locale),
                ],
            ];
        }

        return $events;
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
            $this->logger?->debug('Product missing slug for locale', [
                'product_id' => $product->getId(),
                'locale' => $locale,
            ]);
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
        $scheme = $context->getScheme() ?: 'https';
        $host = $context->getHost();
        $baseUrl = $host ? $scheme . '://' . $host : '';

        return $baseUrl . '/media/image/' . ltrim($path, '/');
    }
}
