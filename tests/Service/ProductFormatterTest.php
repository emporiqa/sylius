<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\ProductFormatter;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionTranslationInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class ProductFormatterTest extends TestCase
{
    private RouterInterface $router;
    private ProductFormatter $formatter;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $context = new RequestContext('', 'GET', 'shop.example.com', 'https');
        $this->router->method('getContext')->willReturn($context);

        $this->formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver(),
            ['en_US'],
        );
    }

    private function createChannel(string $code = 'DEFAULT', string $currencyCode = 'EUR', array $localeCodes = ['en_US']): ChannelInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($currencyCode);

        $locales = [];
        foreach ($localeCodes as $localeCode) {
            $locale = $this->createMock(LocaleInterface::class);
            $locale->method('getCode')->willReturn($localeCode);
            $locales[] = $locale;
        }

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getBaseCurrency')->willReturn($currency);
        $channel->method('getLocales')->willReturn(new ArrayCollection($locales));

        return $channel;
    }

    public function testFormatSimpleProduct(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Test Product');
        $translation->method('getDescription')->willReturn('A test product');
        $translation->method('getSlug')->willReturn('test-product');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1999);
        $channelPricing->method('getOriginalPrice')->willReturn(2499);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('TEST-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('TEST');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/en_US/products/test-product');

        $events = $this->formatter->format($product);

        $this->assertCount(1, $events);
        $this->assertSame('product.updated', $events[0]['type']);
        $this->assertSame('product-1', $events[0]['data']['identification_number']);
        $this->assertSame('TEST-001', $events[0]['data']['sku']);
        $this->assertSame([''], $events[0]['data']['channels']);
        $this->assertSame('Test Product', $events[0]['data']['names']['']['en_US']);
        $this->assertSame('A test product', $events[0]['data']['descriptions']['']['en_US']);
        $this->assertSame('available', $events[0]['data']['availability_statuses']['']);
        $this->assertFalse($events[0]['data']['is_parent']);

        $prices = $events[0]['data']['prices'][''];
        $this->assertCount(1, $prices);
        $this->assertSame('EUR', $prices[0]['currency']);
        $this->assertSame(19.99, $prices[0]['current_price']);
        $this->assertSame(24.99, $prices[0]['regular_price']);
    }

    public function testFormatProductWithVariants(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('T-Shirt');
        $translation->method('getDescription')->willReturn('A t-shirt');
        $translation->method('getSlug')->willReturn('t-shirt');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(2999);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('TSHIRT-S');
        $variant1->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(false);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection());

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('TSHIRT-M');
        $variant2->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection());

        $optionTranslation = $this->createMock(ProductOptionTranslationInterface::class);
        $optionTranslation->method('getName')->willReturn('Size');

        $option = $this->createMock(ProductOptionInterface::class);
        $option->method('getCode')->willReturn('size');
        $option->method('getTranslation')->willReturn($optionTranslation);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(2);
        $product->method('getCode')->willReturn('TSHIRT');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$option]));

        $this->router->method('generate')->willReturn('https://shop.example.com/en_US/products/t-shirt');

        $events = $this->formatter->format($product);

        $this->assertCount(3, $events);
        $this->assertTrue($events[0]['data']['is_parent']);
        $this->assertSame('product-2', $events[0]['data']['identification_number']);
        $this->assertSame('TSHIRT', $events[0]['data']['sku']);
        $this->assertSame(['Size'], $events[0]['data']['variation_attributes']['']['en_US']);
        $this->assertSame([''], $events[0]['data']['channels']);

        $this->assertSame('variation-10', $events[1]['data']['identification_number']);
        $this->assertSame('TSHIRT', $events[1]['data']['parent_sku']);
        $this->assertFalse($events[1]['data']['is_parent']);
    }

    public function testFormatForDeletion(): void
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $events = $this->formatter->formatForDeletion($product);

        $this->assertCount(2, $events);
        $this->assertSame('product.deleted', $events[0]['type']);
        $this->assertSame('product-1', $events[0]['data']['identification_number']);
        $this->assertArrayNotHasKey('language', $events[0]['data']);
        $this->assertSame('product.deleted', $events[1]['type']);
        $this->assertSame('variation-10', $events[1]['data']['identification_number']);
        $this->assertArrayNotHasKey('language', $events[1]['data']);
    }

    public function testFormatVariantForDeletion(): void
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);

        $product = $this->createMock(ProductInterface::class);

        $events = $this->formatter->formatVariantForDeletion($variant, $product);

        $this->assertCount(1, $events);
        $this->assertSame('product.deleted', $events[0]['type']);
        $this->assertSame('variation-10', $events[0]['data']['identification_number']);
        $this->assertArrayNotHasKey('language', $events[0]['data']);
    }

    public function testFormatMultiLanguageConsolidated(): void
    {
        $formatter = new ProductFormatter($this->router, new ChannelMappingResolver(), ['en_US', 'de_DE']);

        $translationEn = $this->createMock(ProductTranslationInterface::class);
        $translationEn->method('getLocale')->willReturn('en_US');
        $translationEn->method('getName')->willReturn('Product');
        $translationEn->method('getDescription')->willReturn('Description');
        $translationEn->method('getSlug')->willReturn('product');

        $translationDe = $this->createMock(ProductTranslationInterface::class);
        $translationDe->method('getLocale')->willReturn('de_DE');
        $translationDe->method('getName')->willReturn('Produkt');
        $translationDe->method('getDescription')->willReturn('Beschreibung');
        $translationDe->method('getSlug')->willReturn('produkt');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('P-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('P');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translationEn, $translationDe]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/products/product');

        $events = $formatter->format($product);

        // Consolidated: one event with both languages
        $this->assertCount(1, $events);
        $this->assertSame('Product', $events[0]['data']['names']['']['en_US']);
        $this->assertSame('Produkt', $events[0]['data']['names']['']['de_DE']);
    }

    public function testFormatProductOutOfStock(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Out of Stock Product');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('oos');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('OOS-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(0);
        $variant->method('getOnHold')->willReturn(0);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('OOS');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/oos');

        $events = $this->formatter->format($product);

        $this->assertCount(1, $events);
        $this->assertSame('out_of_stock', $events[0]['data']['availability_statuses']['']);
        $this->assertSame(0, $events[0]['data']['stock_quantities']['']);
    }

    public function testCategoriesSingleLanguage(): void
    {
        $taxonTranslation = $this->createMock(TaxonTranslationInterface::class);
        $taxonTranslation->method('getName')->willReturn('Laptops');

        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('isRoot')->willReturn(false);
        $taxon->method('getParent')->willReturn(null);
        $taxon->method('getTranslation')->willReturn($taxonTranslation);

        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Laptop');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('laptop');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(100000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('LAP-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('LAP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn($taxon);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/laptop');

        $events = $this->formatter->format($product);

        $this->assertSame(['Laptops'], $events[0]['data']['categories']['']['en_US']);
    }

    public function testCategoriesMultiLanguage(): void
    {
        $formatter = new ProductFormatter($this->router, new ChannelMappingResolver(), ['en_US', 'de_DE']);

        $taxonTranslationEn = $this->createMock(TaxonTranslationInterface::class);
        $taxonTranslationEn->method('getName')->willReturn('Laptops');

        $taxonTranslationDe = $this->createMock(TaxonTranslationInterface::class);
        $taxonTranslationDe->method('getName')->willReturn('Laptops DE');

        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('isRoot')->willReturn(false);
        $taxon->method('getParent')->willReturn(null);
        $taxon->method('getTranslation')->willReturnMap([
            ['en_US', $taxonTranslationEn],
            ['de_DE', $taxonTranslationDe],
        ]);

        $translationEn = $this->createMock(ProductTranslationInterface::class);
        $translationEn->method('getLocale')->willReturn('en_US');
        $translationEn->method('getName')->willReturn('Laptop');
        $translationEn->method('getDescription')->willReturn('');
        $translationEn->method('getSlug')->willReturn('laptop');

        $translationDe = $this->createMock(ProductTranslationInterface::class);
        $translationDe->method('getLocale')->willReturn('de_DE');
        $translationDe->method('getName')->willReturn('Laptop DE');
        $translationDe->method('getDescription')->willReturn('');
        $translationDe->method('getSlug')->willReturn('laptop-de');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(100000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('LAP-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('LAP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translationEn, $translationDe]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn($taxon);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/laptop');

        $events = $formatter->format($product);

        $this->assertSame(['Laptops'], $events[0]['data']['categories']['']['en_US']);
        $this->assertSame(['Laptops DE'], $events[0]['data']['categories']['']['de_DE']);
    }

    public function testVariationAttributesTranslatedPerLanguage(): void
    {
        $formatter = new ProductFormatter($this->router, new ChannelMappingResolver(), ['en_US', 'de_DE']);

        $translationEn = $this->createMock(ProductTranslationInterface::class);
        $translationEn->method('getLocale')->willReturn('en_US');
        $translationEn->method('getName')->willReturn('Jacket');
        $translationEn->method('getDescription')->willReturn('');
        $translationEn->method('getSlug')->willReturn('jacket');

        $translationDe = $this->createMock(ProductTranslationInterface::class);
        $translationDe->method('getLocale')->willReturn('de_DE');
        $translationDe->method('getName')->willReturn('Jacke');
        $translationDe->method('getDescription')->willReturn('');
        $translationDe->method('getSlug')->willReturn('jacke');

        $optionTranslationEn = $this->createMock(ProductOptionTranslationInterface::class);
        $optionTranslationEn->method('getName')->willReturn('Color');

        $optionTranslationDe = $this->createMock(ProductOptionTranslationInterface::class);
        $optionTranslationDe->method('getName')->willReturn('Farbe');

        $option = $this->createMock(ProductOptionInterface::class);
        $option->method('getCode')->willReturn('color');
        $option->method('getTranslation')->willReturnMap([
            ['en_US', $optionTranslationEn],
            ['de_DE', $optionTranslationDe],
        ]);

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(5000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('JACKET-RED');
        $variant1->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(false);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection());

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('JACKET-BLUE');
        $variant2->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection());

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getCode')->willReturn('JACKET');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translationEn, $translationDe]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$option]));

        $this->router->method('generate')->willReturn('https://shop.example.com/jacket');

        $events = $formatter->format($product);

        // Parent product has translated variation_attributes
        $this->assertSame(['Color'], $events[0]['data']['variation_attributes']['']['en_US']);
        $this->assertSame(['Farbe'], $events[0]['data']['variation_attributes']['']['de_DE']);

        // Variant products have empty variation_attributes
        $varAttrs1 = $events[1]['data']['variation_attributes'];
        $this->assertInstanceOf(\stdClass::class, $varAttrs1);
    }

    public function testSimpleProductEmptyVariationAttributes(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Simple Product');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('simple');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SIM-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('SIM');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/simple');

        $events = $this->formatter->format($product);

        $varAttrs = $events[0]['data']['variation_attributes'];
        $this->assertInstanceOf(\stdClass::class, $varAttrs);
    }

    public function testMultiChannelMultiLanguageCategoriesAndVariationAttributes(): void
    {
        $formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver(['WEB' => '', 'B2B' => 'b2b']),
            ['en_US', 'de_DE'],
        );

        $translationEn = $this->createMock(ProductTranslationInterface::class);
        $translationEn->method('getLocale')->willReturn('en_US');
        $translationEn->method('getName')->willReturn('Jacket');
        $translationEn->method('getDescription')->willReturn('');
        $translationEn->method('getSlug')->willReturn('jacket');

        $translationDe = $this->createMock(ProductTranslationInterface::class);
        $translationDe->method('getLocale')->willReturn('de_DE');
        $translationDe->method('getName')->willReturn('Jacke');
        $translationDe->method('getDescription')->willReturn('');
        $translationDe->method('getSlug')->willReturn('jacke');

        $taxonTranslationEn = $this->createMock(TaxonTranslationInterface::class);
        $taxonTranslationEn->method('getName')->willReturn('Outerwear');
        $taxonTranslationDe = $this->createMock(TaxonTranslationInterface::class);
        $taxonTranslationDe->method('getName')->willReturn('Oberbekleidung');

        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('isRoot')->willReturn(false);
        $taxon->method('getParent')->willReturn(null);
        $taxon->method('getTranslation')->willReturnMap([
            ['en_US', $taxonTranslationEn],
            ['de_DE', $taxonTranslationDe],
        ]);

        $optionTranslationEn = $this->createMock(ProductOptionTranslationInterface::class);
        $optionTranslationEn->method('getName')->willReturn('Size');
        $optionTranslationDe = $this->createMock(ProductOptionTranslationInterface::class);
        $optionTranslationDe->method('getName')->willReturn('Größe');

        $option = $this->createMock(ProductOptionInterface::class);
        $option->method('getCode')->willReturn('size');
        $option->method('getTranslation')->willReturnMap([
            ['en_US', $optionTranslationEn],
            ['de_DE', $optionTranslationDe],
        ]);

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(5000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $webChannel = $this->createChannel('WEB', 'EUR', ['en_US', 'de_DE']);
        $b2bChannel = $this->createChannel('B2B', 'USD', ['en_US']);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('JACKET-S');
        $variant1->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(false);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection());

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('JACKET-M');
        $variant2->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection());

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getCode')->willReturn('JACKET');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translationEn, $translationDe]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$webChannel, $b2bChannel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn($taxon);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$option]));

        $this->router->method('generate')->willReturn('https://shop.example.com/jacket');

        $events = $formatter->format($product);

        $parent = $events[0]['data'];

        // WEB channel ("") has both en and de
        $this->assertSame(['Outerwear'], $parent['categories']['']['en_US']);
        $this->assertSame(['Oberbekleidung'], $parent['categories']['']['de_DE']);
        $this->assertSame(['Size'], $parent['variation_attributes']['']['en_US']);
        $this->assertSame(['Größe'], $parent['variation_attributes']['']['de_DE']);

        // B2B channel ("b2b") has only en (channel has only en_US locale)
        $this->assertSame(['Outerwear'], $parent['categories']['b2b']['en_US']);
        $this->assertArrayNotHasKey('de_DE', $parent['categories']['b2b']);
        $this->assertSame(['Size'], $parent['variation_attributes']['b2b']['en_US']);
        $this->assertArrayNotHasKey('de_DE', $parent['variation_attributes']['b2b']);

        // Variant categories also translatable per channel
        $variantData = $events[1]['data'];
        $this->assertSame(['Outerwear'], $variantData['categories']['']['en_US']);
        $this->assertSame(['Oberbekleidung'], $variantData['categories']['']['de_DE']);
        $this->assertSame(['Outerwear'], $variantData['categories']['b2b']['en_US']);
        $this->assertInstanceOf(\stdClass::class, $variantData['variation_attributes']);
    }

    public function testFormatWithChannelMapping(): void
    {
        $formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver(['WEB' => '', 'B2B' => 'b2b']),
            ['en_US'],
        );

        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Product');
        $translation->method('getDescription')->willReturn('Desc');
        $translation->method('getSlug')->willReturn('product');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel1 = $this->createChannel('WEB', 'EUR');
        $channel2 = $this->createChannel('B2B', 'USD');

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('P-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('P');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel1, $channel2]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/product');

        $events = $formatter->format($product);

        $this->assertCount(1, $events);
        $this->assertSame(['', 'b2b'], $events[0]['data']['channels']);
        $this->assertArrayHasKey('', $events[0]['data']['names']);
        $this->assertArrayHasKey('b2b', $events[0]['data']['names']);
        $this->assertSame('EUR', $events[0]['data']['prices'][''][0]['currency']);
        $this->assertSame('USD', $events[0]['data']['prices']['b2b'][0]['currency']);
    }

    public function testFormatAutoDetectsMultipleChannels(): void
    {
        $ch1 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');
        $ch2 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch2->method('getCode')->willReturn('B2B');
        $ch3 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch3->method('getCode')->willReturn('WHOLESALE');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2, $ch3]);

        $formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver([], $channelRepo),
            ['en_US'],
            '',
            'brand',
        );

        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Test');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('test');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $defaultChannel = $this->createChannel('default', 'EUR');
        $b2bChannel = $this->createChannel('B2B', 'USD');

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('T-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('T');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$defaultChannel, $b2bChannel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/test');

        $events = $formatter->format($product);

        // default → "", B2B → "b2b"
        $this->assertSame(['', 'b2b'], $events[0]['data']['channels']);
    }

    public function testFormatSingleAutoDetectedChannelUsesDefault(): void
    {
        $ch1 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1]);

        $formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver([], $channelRepo),
            ['en_US'],
            '',
            'brand',
        );

        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Single');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('single');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(500);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('default', 'EUR');

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('S-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('S');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/single');

        $events = $formatter->format($product);

        // Single channel → all maps to ""
        $this->assertSame([''], $events[0]['data']['channels']);
    }

    public function testFormatProductNoChannelsReturnsEmpty(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getChannels')->willReturn(new ArrayCollection());

        $events = $this->formatter->format($product);

        $this->assertEmpty($events);
    }

    public function testFormatDisabledProductIsOutOfStock(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Disabled');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('disabled');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('DIS-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('DIS');
        $product->method('isEnabled')->willReturn(false);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/disabled');

        $events = $this->formatter->format($product);

        $this->assertSame('out_of_stock', $events[0]['data']['availability_statuses']['']);
    }

    public function testFormatDisabledVariantIsOutOfStock(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Product');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('product');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('V-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(false);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('V');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/product');

        $events = $this->formatter->format($product);

        $this->assertSame('out_of_stock', $events[0]['data']['availability_statuses']['']);
    }

    public function testFormatDescriptionNullDefaultsToEmptyString(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('No Desc');
        $translation->method('getDescription')->willReturn(null);
        $translation->method('getSlug')->willReturn('no-desc');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('ND-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('ND');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/no-desc');

        $events = $this->formatter->format($product);

        $this->assertSame('', $events[0]['data']['descriptions']['']['en_US']);
    }

    public function testFormatWithJPYCurrencyNoDivision(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('JPY Product');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('jpy');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1999);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('JAPAN', 'JPY');

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('JPY-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('JPY');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/jpy');

        $events = $this->formatter->format($product);

        $this->assertSame(1999.0, $events[0]['data']['prices'][''][0]['current_price']);
        $this->assertSame('JPY', $events[0]['data']['prices'][''][0]['currency']);
    }

    public function testFormatSkipsLocalesWithMissingTranslation(): void
    {
        $formatter = new ProductFormatter($this->router, new ChannelMappingResolver(), ['en_US', 'de_DE']);

        $translationEn = $this->createMock(ProductTranslationInterface::class);
        $translationEn->method('getLocale')->willReturn('en_US');
        $translationEn->method('getName')->willReturn('Product');
        $translationEn->method('getDescription')->willReturn('Desc');
        $translationEn->method('getSlug')->willReturn('product');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('P-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('P');
        $product->method('isEnabled')->willReturn(true);
        // Only en_US translation, no de_DE
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translationEn]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/product');

        $events = $formatter->format($product);

        $this->assertArrayHasKey('en_US', $events[0]['data']['names']['']);
        $this->assertArrayNotHasKey('de_DE', $events[0]['data']['names']['']);
    }

    public function testFormatFreeProductPriceZero(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Free Sample');
        $translation->method('getDescription')->willReturn('A free product');
        $translation->method('getSlug')->willReturn('free-sample');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(0);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('FREE-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('FREE');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/free-sample');

        $events = $this->formatter->format($product);

        $this->assertCount(1, $events);
        $prices = $events[0]['data']['prices'][''];
        $this->assertCount(1, $prices);
        $this->assertEquals(0.0, $prices[0]['current_price']);
        $this->assertEquals(0.0, $prices[0]['regular_price']);
        $this->assertSame('EUR', $prices[0]['currency']);
    }

    public function testFormatFreeProductWithOriginalPrice(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Sale Product');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('sale');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(0);
        $channelPricing->method('getOriginalPrice')->willReturn(1999);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SALE-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('SALE');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/sale');

        $events = $this->formatter->format($product);

        $prices = $events[0]['data']['prices'][''];
        $this->assertCount(1, $prices);
        $this->assertEquals(0.0, $prices[0]['current_price']);
        $this->assertSame(19.99, $prices[0]['regular_price']);
    }

    public function testAutoDetectSkipsChannelsWithNullCode(): void
    {
        $ch1 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');

        $ch2 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch2->method('getCode')->willReturn(null);

        $ch3 = $this->createMock(\Sylius\Component\Channel\Model\ChannelInterface::class);
        $ch3->method('getCode')->willReturn('B2B');

        $channelRepo = $this->createMock(\Sylius\Component\Channel\Repository\ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2, $ch3]);

        $formatter = new ProductFormatter(
            $this->router,
            new ChannelMappingResolver([], $channelRepo),
            ['en_US'],
            '',
            'brand',
        );

        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('Test');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('test');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $defaultChannel = $this->createChannel('default', 'EUR');
        $b2bChannel = $this->createChannel('B2B', 'USD');

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('T-001');
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('T');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$defaultChannel, $b2bChannel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/test');

        $events = $formatter->format($product);

        // Null-code channel is skipped, so we get "" (default) and "b2b"
        $this->assertSame(['', 'b2b'], $events[0]['data']['channels']);
    }

    public function testFormatProductWithNullCode(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('No Code');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('no-code');

        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn(1000);
        $channelPricing->method('getOriginalPrice')->willReturn(null);

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn(null);
        $variant->method('getChannelPricingForChannel')->willReturn($channelPricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn(null);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/no-code');

        $events = $this->formatter->format($product);

        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]['data']['sku']);
    }

    public function testFormatVariantNoPriceReturnsEmptyPrices(): void
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getLocale')->willReturn('en_US');
        $translation->method('getName')->willReturn('No Price');
        $translation->method('getDescription')->willReturn('');
        $translation->method('getSlug')->willReturn('no-price');

        $channel = $this->createChannel();

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('NP-001');
        $variant->method('getChannelPricingForChannel')->willReturn(null);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('NP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([$translation]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $this->router->method('generate')->willReturn('https://shop.example.com/no-price');

        $events = $this->formatter->format($product);

        $this->assertEmpty($events[0]['data']['prices']['']);
    }
}
