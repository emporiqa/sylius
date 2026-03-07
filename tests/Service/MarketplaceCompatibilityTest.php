<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Service\ProductFormatter;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Model\ProductAttributeTranslationInterface;
use Sylius\Component\Product\Model\ProductAttributeValueInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionTranslationInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Component\Product\Model\ProductOptionValueTranslationInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Validates that the ProductFormatter output matches the Emporiqa webhook schema
 * for all marketplace-relevant scenarios: JSON structure, field types, translatable
 * nesting, multichannel, and edge cases.
 */
class MarketplaceCompatibilityTest extends TestCase
{
    private RouterInterface $router;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $context = new RequestContext('', 'GET', 'shop.example.com', 'https');
        $this->router->method('getContext')->willReturn($context);
        $this->router->method('generate')->willReturn('https://shop.example.com/product');
    }

    // -- helpers --

    private function createLocale(string $code): LocaleInterface
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn($code);
        return $locale;
    }

    private function createCurrency(string $code): CurrencyInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($code);
        return $currency;
    }

    private function createChannel(string $code, string $currencyCode, array $localeCodes): ChannelInterface
    {
        $locales = array_map(fn(string $c) => $this->createLocale($c), $localeCodes);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getBaseCurrency')->willReturn($this->createCurrency($currencyCode));
        $channel->method('getLocales')->willReturn(new ArrayCollection($locales));
        return $channel;
    }

    private function createTranslation(string $locale, string $name, string $desc = '', string $slug = 'slug'): ProductTranslationInterface
    {
        $t = $this->createMock(ProductTranslationInterface::class);
        $t->method('getLocale')->willReturn($locale);
        $t->method('getName')->willReturn($name);
        $t->method('getDescription')->willReturn($desc);
        $t->method('getSlug')->willReturn($slug);
        return $t;
    }

    private function createPricing(int $price, ?int $original = null): ChannelPricingInterface
    {
        $p = $this->createMock(ChannelPricingInterface::class);
        $p->method('getPrice')->willReturn($price);
        $p->method('getOriginalPrice')->willReturn($original);
        return $p;
    }

    private function createTaxon(string $enName, ?string $deName = null, ?TaxonInterface $parent = null, bool $isRoot = false): TaxonInterface
    {
        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('isRoot')->willReturn($isRoot);
        $taxon->method('getParent')->willReturn($parent);

        if ($deName !== null) {
            $trEn = $this->createMock(TaxonTranslationInterface::class);
            $trEn->method('getName')->willReturn($enName);
            $trDe = $this->createMock(TaxonTranslationInterface::class);
            $trDe->method('getName')->willReturn($deName);
            $taxon->method('getTranslation')->willReturnMap([
                ['en_US', $trEn],
                ['de_DE', $trDe],
            ]);
        } else {
            $trEn = $this->createMock(TaxonTranslationInterface::class);
            $trEn->method('getName')->willReturn($enName);
            $taxon->method('getTranslation')->willReturn($trEn);
        }

        return $taxon;
    }

    private function createOption(string $code, string $enName, ?string $deName = null): ProductOptionInterface
    {
        $option = $this->createMock(ProductOptionInterface::class);
        $option->method('getCode')->willReturn($code);

        if ($deName !== null) {
            $trEn = $this->createMock(ProductOptionTranslationInterface::class);
            $trEn->method('getName')->willReturn($enName);
            $trDe = $this->createMock(ProductOptionTranslationInterface::class);
            $trDe->method('getName')->willReturn($deName);
            $option->method('getTranslation')->willReturnMap([
                ['en_US', $trEn],
                ['de_DE', $trDe],
            ]);
        } else {
            $tr = $this->createMock(ProductOptionTranslationInterface::class);
            $tr->method('getName')->willReturn($enName);
            $option->method('getTranslation')->willReturn($tr);
        }

        return $option;
    }

    private function createOptionValue(ProductOptionInterface $option, string $enVal, ?string $deVal = null): ProductOptionValueInterface
    {
        $ov = $this->createMock(ProductOptionValueInterface::class);
        $ov->method('getOption')->willReturn($option);
        $ov->method('getCode')->willReturn(strtolower($enVal));

        if ($deVal !== null) {
            $trEn = $this->createMock(ProductOptionValueTranslationInterface::class);
            $trEn->method('getValue')->willReturn($enVal);
            $trDe = $this->createMock(ProductOptionValueTranslationInterface::class);
            $trDe->method('getValue')->willReturn($deVal);
            $ov->method('getTranslation')->willReturnMap([
                ['en_US', $trEn],
                ['de_DE', $trDe],
            ]);
        } else {
            $tr = $this->createMock(ProductOptionValueTranslationInterface::class);
            $tr->method('getValue')->willReturn($enVal);
            $ov->method('getTranslation')->willReturn($tr);
        }

        return $ov;
    }

    private function createAttribute(string $code, string $enName, $value, ?string $deName = null): ProductAttributeValueInterface
    {
        $attrTranslation = $this->createMock(ProductAttributeTranslationInterface::class);
        $attrTranslation->method('getName')->willReturn($enName);

        $attr = $this->createMock(ProductAttributeInterface::class);
        $attr->method('getCode')->willReturn($code);

        if ($deName !== null) {
            $trEn = $this->createMock(ProductAttributeTranslationInterface::class);
            $trEn->method('getName')->willReturn($enName);
            $trDe = $this->createMock(ProductAttributeTranslationInterface::class);
            $trDe->method('getName')->willReturn($deName);
            $attr->method('getTranslation')->willReturnMap([
                ['en_US', $trEn],
                ['de_DE', $trDe],
            ]);
        } else {
            $attr->method('getTranslation')->willReturn($attrTranslation);
        }

        $av = $this->createMock(ProductAttributeValueInterface::class);
        $av->method('getAttribute')->willReturn($attr);
        $av->method('getValue')->willReturn($value);
        return $av;
    }

    // ============================================================
    // JSON structure compliance tests
    // ============================================================

    /**
     * Validates the complete JSON payload structure matches the Emporiqa schema
     * for a fully populated multilingual product with variants.
     */
    public function testFullSchemaCompliance(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], ['WEB' => '']);

        $channel = $this->createChannel('WEB', 'EUR', ['en_US', 'de_DE']);

        $rootTaxon = $this->createTaxon('Root', null, null, true);
        $parentTaxon = $this->createTaxon('Electronics', 'Elektronik', $rootTaxon);
        $leafTaxon = $this->createTaxon('Laptops', 'Laptops', $parentTaxon);

        $colorOption = $this->createOption('color', 'Color', 'Farbe');
        $sizeOption = $this->createOption('size', 'Size', 'Größe');

        $colorValue = $this->createOptionValue($colorOption, 'Blue', 'Blau');
        $sizeValue = $this->createOptionValue($sizeOption, 'M', 'M');

        $brandAttr = $this->createAttribute('brand', 'Brand', 'TrailPeak', 'Marke');
        $materialAttr = $this->createAttribute('material', 'Material', 'Nylon', 'Material');

        $pricing = $this->createPricing(7999, 9999);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('LAPTOP-BLUE-M');
        $variant1->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(true);
        $variant1->method('getOnHand')->willReturn(42);
        $variant1->method('getOnHold')->willReturn(0);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection([$colorValue, $sizeValue]));

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('LAPTOP-BLUE-L');
        $variant2->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(true);
        $variant2->method('getOnHand')->willReturn(0);
        $variant2->method('getOnHold')->willReturn(0);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection([$colorValue]));

        $image = $this->createMock(ImageInterface::class);
        $image->method('getPath')->willReturn('laptop.jpg');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(123);
        $product->method('getCode')->willReturn('LAPTOP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Trail Laptop', 'Lightweight laptop', 'trail-laptop'),
            $this->createTranslation('de_DE', 'Wander-Laptop', 'Leichter Laptop', 'wander-laptop'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn($leafTaxon);
        $product->method('getAttributes')->willReturn(new ArrayCollection([$brandAttr, $materialAttr]));
        $product->method('getImages')->willReturn(new ArrayCollection([$image]));
        $product->method('getOptions')->willReturn(new ArrayCollection([$colorOption, $sizeOption]));

        $events = $formatter->format($product);

        // 1 parent + 2 variants = 3 events
        $this->assertCount(3, $events);

        // -- Validate parent event --
        $parent = $events[0];
        $this->assertSame('product.updated', $parent['type']);
        $d = $parent['data'];

        // Top-level required fields
        $this->assertSame('product-123', $d['identification_number']);
        $this->assertSame('LAPTOP', $d['sku']);
        $this->assertSame([''], $d['channels']);
        $this->assertNull($d['parent_sku']);
        $this->assertTrue($d['is_parent']);

        // Translatable fields: {channel: {lang: value}}
        $this->assertSame('Trail Laptop', $d['names']['']['en_US']);
        $this->assertSame('Wander-Laptop', $d['names']['']['de_DE']);
        $this->assertSame('Lightweight laptop', $d['descriptions']['']['en_US']);
        $this->assertSame('Leichter Laptop', $d['descriptions']['']['de_DE']);
        $this->assertIsString($d['links']['']['en_US']);
        $this->assertIsString($d['links']['']['de_DE']);

        // Categories: {channel: {lang: [paths]}}
        $this->assertIsArray($d['categories']['']['en_US']);
        $this->assertIsArray($d['categories']['']['de_DE']);
        $this->assertStringContainsString('>', $d['categories']['']['en_US'][0]);
        $this->assertStringContainsString('>', $d['categories']['']['de_DE'][0]);

        // Attributes: {channel: {lang: {name: value}}}
        $this->assertArrayHasKey('Material', $d['attributes']['']['en_US']);
        $this->assertArrayHasKey('Material', $d['attributes']['']['de_DE']);

        // variation_attributes: {channel: {lang: [names]}}
        $this->assertSame(['Color', 'Size'], $d['variation_attributes']['']['en_US']);
        $this->assertSame(['Farbe', 'Größe'], $d['variation_attributes']['']['de_DE']);

        // Shared per-channel fields (not nested by language)
        $this->assertSame('TrailPeak', $d['brands']['']);
        $this->assertIsArray($d['prices']['']);
        $this->assertSame('EUR', $d['prices'][''][0]['currency']);
        $this->assertSame(79.99, $d['prices'][''][0]['current_price']);
        $this->assertSame(99.99, $d['prices'][''][0]['regular_price']);
        $this->assertNull($d['stock_quantities']['']);
        $this->assertSame(['https://shop.example.com/media/image/laptop.jpg'], $d['images']['']);

        // -- Validate variant event --
        $v1 = $events[1];
        $this->assertSame('product.updated', $v1['type']);
        $vd = $v1['data'];

        $this->assertSame('variation-10', $vd['identification_number']);
        $this->assertSame('LAPTOP-BLUE-M', $vd['sku']);
        $this->assertSame('LAPTOP', $vd['parent_sku']);
        $this->assertFalse($vd['is_parent']);
        $this->assertSame(42, $vd['stock_quantities']['']);
        $this->assertSame('available', $vd['availability_statuses']['']);

        // Variant variation_attributes must be empty object (not array)
        $this->assertInstanceOf(\stdClass::class, $vd['variation_attributes']);

        // Variant attributes include option values merged with product attributes
        $this->assertArrayHasKey('Color', $vd['attributes']['']['en_US']);
        $this->assertArrayHasKey('Farbe', $vd['attributes']['']['de_DE']);

        // Variant name includes option suffix
        $this->assertStringContainsString('Blue', $vd['names']['']['en_US']);
        $this->assertStringContainsString('Blau', $vd['names']['']['de_DE']);

        // Out-of-stock variant
        $v2 = $events[2];
        $this->assertSame('out_of_stock', $v2['data']['availability_statuses']['']);
        $this->assertSame(0, $v2['data']['stock_quantities']['']);
    }

    /**
     * Validates that json_encode produces the expected structure
     * (objects vs arrays in the right places).
     */
    public function testJsonSerializationFormat(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);

        $channel = $this->createChannel('DEFAULT', 'USD', ['en_US']);
        $pricing = $this->createPricing(1000);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SIMPLE-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('SIMPLE');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Simple Product', 'Desc', 'simple'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $json = json_encode(['events' => $events], JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        $data = $decoded['events'][0]['data'];

        // channels must be an array
        $this->assertSame([''], $data['channels']);

        // names must be {channel: {lang: string}}
        $this->assertIsString($data['names']['']['en_US']);

        // categories must be {channel: {lang: [strings]}}
        $this->assertIsArray($data['categories']['']['en_US']);

        // prices must be {channel: [{currency, current_price, ...}]}
        $this->assertIsArray($data['prices']['']);
        $this->assertArrayHasKey('currency', $data['prices'][''][0]);

        // variation_attributes for simple product: empty object {}
        $jsonRaw = json_encode($events[0]['data']['variation_attributes']);
        $this->assertSame('{}', $jsonRaw);

        // brands: {channel: string}
        $this->assertIsString($data['brands']['']);

        // images: {channel: [strings]}
        $this->assertIsArray($data['images']['']);
    }

    /**
     * Multichannel: channels with different currencies, locales, and prices.
     */
    public function testMultiChannelDifferentCurrenciesAndLocales(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE', 'fr_FR'], [
            'WEB_EU' => '',
            'WEB_US' => 'us',
        ]);

        $euChannel = $this->createChannel('WEB_EU', 'EUR', ['en_US', 'de_DE']);
        $usChannel = $this->createChannel('WEB_US', 'USD', ['en_US']);

        $euPricing = $this->createPricing(4999);
        $usPricing = $this->createPricing(5499);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('PROD-001');
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);
        $variant->method('getChannelPricingForChannel')->willReturnMap([
            [$euChannel, $euPricing],
            [$usChannel, $usPricing],
        ]);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('PROD');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Product', 'Desc EN', 'product'),
            $this->createTranslation('de_DE', 'Produkt', 'Desc DE', 'produkt'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$euChannel, $usChannel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        // Both channel keys present
        $this->assertSame(['', 'us'], $d['channels']);

        // EU channel: en_US + de_DE
        $this->assertSame('Product', $d['names']['']['en_US']);
        $this->assertSame('Produkt', $d['names']['']['de_DE']);
        $this->assertArrayHasKey('en_US', $d['categories']['']);
        $this->assertArrayHasKey('de_DE', $d['categories']['']);

        // US channel: en_US only (channel has only en_US)
        $this->assertSame('Product', $d['names']['us']['en_US']);
        $this->assertArrayNotHasKey('de_DE', $d['names']['us']);
        $this->assertArrayHasKey('en_US', $d['categories']['us']);
        $this->assertArrayNotHasKey('de_DE', $d['categories']['us']);

        // Different currencies per channel
        $this->assertSame('EUR', $d['prices'][''][0]['currency']);
        $this->assertSame(49.99, $d['prices'][''][0]['current_price']);
        $this->assertSame('USD', $d['prices']['us'][0]['currency']);
        $this->assertSame(54.99, $d['prices']['us'][0]['current_price']);
    }

    /**
     * Variation attributes must match attribute keys per language.
     */
    public function testVariationAttributeNamesMatchAttributeKeysPerLanguage(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);

        $colorOption = $this->createOption('color', 'Color', 'Farbe');
        $colorValue = $this->createOptionValue($colorOption, 'Red', 'Rot');

        $pricing = $this->createPricing(2000);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('V1');
        $variant1->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(false);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection([$colorValue]));

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('V2');
        $variant2->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection([$colorValue]));

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getCode')->willReturn('P5');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Product', '', 'product'),
            $this->createTranslation('de_DE', 'Produkt', '', 'produkt'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$colorOption]));

        $events = $formatter->format($product);
        $parent = $events[0]['data'];
        $variant = $events[1]['data'];

        // Parent: variation_attributes keys must match the language-specific attribute keys on variants
        $this->assertSame(['Color'], $parent['variation_attributes']['']['en_US']);
        $this->assertSame(['Farbe'], $parent['variation_attributes']['']['de_DE']);

        // Variant: attributes must contain the same keys
        $this->assertArrayHasKey('Color', $variant['attributes']['']['en_US']);
        $this->assertArrayHasKey('Farbe', $variant['attributes']['']['de_DE']);

        // The values must match
        $this->assertSame('Red', $variant['attributes']['']['en_US']['Color']);
        $this->assertSame('Rot', $variant['attributes']['']['de_DE']['Farbe']);
    }

    /**
     * Category hierarchy builds the full path with " > " separator.
     */
    public function testCategoryHierarchyPath(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE']);
        $pricing = $this->createPricing(1000);

        $root = $this->createTaxon('Root', 'Wurzel', null, true);
        $mid = $this->createTaxon('Clothing', 'Bekleidung', $root);
        $leaf = $this->createTaxon('Jackets', 'Jacken', $mid);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('J-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('J');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Jacket', '', 'jacket'),
            $this->createTranslation('de_DE', 'Jacke', '', 'jacke'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn($leaf);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        $this->assertSame(['Clothing > Jackets'], $d['categories']['']['en_US']);
        $this->assertSame(['Bekleidung > Jacken'], $d['categories']['']['de_DE']);
    }

    /**
     * Product with no categories produces empty arrays per language.
     */
    public function testProductWithNoCategories(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US']);
        $pricing = $this->createPricing(1000);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('NC-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('NC');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'No Cat Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $this->assertSame([], $events[0]['data']['categories']['']['en_US']);
    }

    /**
     * Product with no images produces empty array per channel.
     */
    public function testProductWithNoImages(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US']);
        $pricing = $this->createPricing(1000);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('NI-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('NI');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'No Image Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $this->assertSame([], $events[0]['data']['images']['']);
    }

    /**
     * Product with no variant pricing produces empty price array.
     */
    public function testProductWithNoPricing(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US']);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('NP-001');
        $variant->method('getChannelPricingForChannel')->willReturn(null);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('NP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'No Price Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $this->assertSame([], $events[0]['data']['prices']['']);
    }

    /**
     * Disabled product is out_of_stock.
     */
    public function testDisabledProductAvailability(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US']);
        $pricing = $this->createPricing(1000);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('DIS-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('DIS');
        $product->method('isEnabled')->willReturn(false);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Disabled Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $this->assertSame('out_of_stock', $events[0]['data']['availability_statuses']['']);
    }

    /**
     * Disabled variant is out_of_stock even if product is enabled.
     */
    public function testDisabledVariantAvailability(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US']);
        $pricing = $this->createPricing(1000);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('EN-001');
        $variant1->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(false);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection());

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('DIS-002');
        $variant2->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant2->method('isEnabled')->willReturn(false);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection());

        $option = $this->createOption('size', 'Size');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('MIX');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Mixed Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$option]));

        $events = $formatter->format($product);
        // parent + 2 variants
        $this->assertSame('available', $events[1]['data']['availability_statuses']['']);
        $this->assertSame('out_of_stock', $events[2]['data']['availability_statuses']['']);
    }

    /**
     * Unmapped channels default to "" key — verifies data isn't lost.
     */
    public function testUnmappedChannelsDefaultToEmptyKey(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);
        $channel = $this->createChannel('SOME_CHANNEL', 'GBP', ['en_US']);
        $pricing = $this->createPricing(1500);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('UK-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('UK');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'UK Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        $this->assertSame([''], $d['channels']);
        $this->assertSame('GBP', $d['prices'][''][0]['currency']);
        $this->assertSame('UK Product', $d['names']['']['en_US']);
    }

    /**
     * Multiple unmapped channels collapse to same key — last one wins for shared fields.
     * This verifies the behavior is deterministic.
     */
    public function testMultipleUnmappedChannelsCollapse(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);

        $ch1 = $this->createChannel('CH_A', 'EUR', ['en_US']);
        $ch2 = $this->createChannel('CH_B', 'USD', ['en_US']);

        $eurPricing = $this->createPricing(1000);
        $usdPricing = $this->createPricing(1200);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('COL-001');
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);
        $variant->method('getChannelPricingForChannel')->willReturnMap([
            [$ch1, $eurPricing],
            [$ch2, $usdPricing],
        ]);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('COL');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Collapsed Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$ch1, $ch2]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        // Only one channel key (both unmapped → "")
        $this->assertSame([''], $d['channels']);

        // Last channel (CH_B/USD) overwrites shared fields
        $this->assertSame('USD', $d['prices'][''][0]['currency']);

        // Translatable fields also overwritten but with same value
        $this->assertSame('Collapsed Product', $d['names']['']['en_US']);
    }

    /**
     * Deletion events contain only identification_number, no translatable fields.
     */
    public function testDeletionEventMinimalPayload(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], []);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $events = $formatter->formatForDeletion($product);

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('product.deleted', $event['type']);
            $this->assertArrayHasKey('identification_number', $event['data']);
            // Must NOT contain translatable fields
            $this->assertArrayNotHasKey('names', $event['data']);
            $this->assertArrayNotHasKey('categories', $event['data']);
            $this->assertArrayNotHasKey('variation_attributes', $event['data']);
            $this->assertArrayNotHasKey('channels', $event['data']);
        }
    }

    /**
     * Product with no channels produces empty events array.
     */
    public function testProductWithNoChannels(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US'], []);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getChannels')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $this->assertSame([], $events);
    }

    /**
     * Product with translations missing for a locale skips that language.
     */
    public function testMissingTranslationSkipsLanguage(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE', 'fr_FR'], []);
        $channel = $this->createChannel('DEFAULT', 'EUR', ['en_US', 'de_DE', 'fr_FR']);
        $pricing = $this->createPricing(1000);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('MT-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        // Only en and de translations — no fr
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('MT');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'English Product', 'EN desc', 'en-product'),
            $this->createTranslation('de_DE', 'German Product', 'DE desc', 'de-product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        $this->assertArrayHasKey('en_US', $d['names']['']);
        $this->assertArrayHasKey('de_DE', $d['names']['']);
        $this->assertArrayNotHasKey('fr_FR', $d['names']['']);

        // Categories also skip missing locale
        $this->assertArrayHasKey('en_US', $d['categories']['']);
        $this->assertArrayHasKey('de_DE', $d['categories']['']);
        $this->assertArrayNotHasKey('fr_FR', $d['categories']['']);
    }

    /**
     * The full payload survives a json_encode → json_decode roundtrip
     * without any type or structure changes.
     */
    public function testJsonRoundtripIntegrity(): void
    {
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], ['WEB' => '']);
        $channel = $this->createChannel('WEB', 'EUR', ['en_US', 'de_DE']);

        $option = $this->createOption('color', 'Color', 'Farbe');
        $optionValue = $this->createOptionValue($option, 'Red', 'Rot');
        $pricing = $this->createPricing(2999, 3999);

        $variant1 = $this->createMock(ProductVariantInterface::class);
        $variant1->method('getId')->willReturn(10);
        $variant1->method('getCode')->willReturn('P-RED');
        $variant1->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant1->method('isEnabled')->willReturn(true);
        $variant1->method('isTracked')->willReturn(true);
        $variant1->method('getOnHand')->willReturn(5);
        $variant1->method('getOnHold')->willReturn(1);
        $variant1->method('getOptionValues')->willReturn(new ArrayCollection([$optionValue]));

        $variant2 = $this->createMock(ProductVariantInterface::class);
        $variant2->method('getId')->willReturn(11);
        $variant2->method('getCode')->willReturn('P-BLUE');
        $variant2->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant2->method('isEnabled')->willReturn(true);
        $variant2->method('isTracked')->willReturn(false);
        $variant2->method('getOptionValues')->willReturn(new ArrayCollection([$optionValue]));

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(42);
        $product->method('getCode')->willReturn('ROUNDTRIP');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Roundtrip Product', 'English', 'roundtrip'),
            $this->createTranslation('de_DE', 'Roundtrip Produkt', 'Deutsch', 'roundtrip-de'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant1, $variant2]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());
        $product->method('getOptions')->willReturn(new ArrayCollection([$option]));

        $events = $formatter->format($product);
        $payload = ['events' => $events];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true);

        // 3 events survive roundtrip
        $this->assertCount(3, $decoded['events']);

        $parent = $decoded['events'][0]['data'];
        $this->assertTrue($parent['is_parent']);
        $this->assertNull($parent['parent_sku']);
        $this->assertNull($parent['stock_quantities']['']);
        $this->assertIsArray($parent['variation_attributes']['']['en_US']);
        $this->assertIsArray($parent['variation_attributes']['']['de_DE']);

        $v1 = $decoded['events'][1]['data'];
        $this->assertFalse($v1['is_parent']);
        $this->assertSame('ROUNDTRIP', $v1['parent_sku']);
        $this->assertSame(4, $v1['stock_quantities']['']);

        // variation_attributes on variants serializes to {} (empty object)
        $rawJson = json_encode($events[1]['data']['variation_attributes']);
        $this->assertSame('{}', $rawJson);
        // After decode it becomes empty associative array
        $this->assertEmpty($decoded['events'][1]['data']['variation_attributes']);
    }

    // ============================================================
    // Channel auto-detection tests
    // ============================================================

    private function createSimpleProduct(ChannelInterface $channel, ChannelPricingInterface $pricing): ProductInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('AUTO-001');
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('AUTO');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Auto Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        return $product;
    }

    private function createMultiChannelProduct(array $channels, array $pricingMap): ProductInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('MC-001');
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);
        $variant->method('getChannelPricingForChannel')->willReturnMap($pricingMap);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('getCode')->willReturn('MC');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getTranslations')->willReturn(new ArrayCollection([
            $this->createTranslation('en_US', 'Multi Channel Product'),
        ]));
        $product->method('getChannels')->willReturn(new ArrayCollection($channels));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getAttributes')->willReturn(new ArrayCollection());
        $product->method('getImages')->willReturn(new ArrayCollection());

        return $product;
    }

    /**
     * Single channel in store: auto-maps to "" (same as no mapping).
     */
    public function testAutoDetectSingleChannel(): void
    {
        $channel = $this->createChannel('MAIN', 'EUR', ['en_US']);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$channel]);

        $formatter = new ProductFormatter(
            $this->router, ['en_US'], [], '', 'brand', null, $channelRepo,
        );

        $product = $this->createSimpleProduct($channel, $this->createPricing(1000));
        $events = $formatter->format($product);

        $this->assertSame([''], $events[0]['data']['channels']);
        $this->assertSame('EUR', $events[0]['data']['prices'][''][0]['currency']);
    }

    /**
     * Multiple channels in store with no explicit mapping:
     * first channel → "", rest → lowercased code.
     */
    public function testAutoDetectMultipleChannels(): void
    {
        $webChannel = $this->createChannel('WEB_EU', 'EUR', ['en_US']);
        $b2bChannel = $this->createChannel('B2B', 'USD', ['en_US']);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$webChannel, $b2bChannel]);

        $formatter = new ProductFormatter(
            $this->router, ['en_US'], [], '', 'brand', null, $channelRepo,
        );

        $eurPricing = $this->createPricing(4999);
        $usdPricing = $this->createPricing(5499);

        $product = $this->createMultiChannelProduct(
            [$webChannel, $b2bChannel],
            [[$webChannel, $eurPricing], [$b2bChannel, $usdPricing]],
        );

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        // First channel → "", second → "b2b"
        $this->assertSame(['', 'b2b'], $d['channels']);
        $this->assertSame('EUR', $d['prices'][''][0]['currency']);
        $this->assertSame('USD', $d['prices']['b2b'][0]['currency']);
        $this->assertSame('Multi Channel Product', $d['names']['']['en_US']);
        $this->assertSame('Multi Channel Product', $d['names']['b2b']['en_US']);
    }

    /**
     * Explicit mapping takes precedence over auto-detection.
     */
    public function testExplicitMappingOverridesAutoDetect(): void
    {
        $webChannel = $this->createChannel('WEB_EU', 'EUR', ['en_US']);
        $b2bChannel = $this->createChannel('B2B', 'USD', ['en_US']);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        // findAll should never be called when explicit mapping exists
        $channelRepo->expects($this->never())->method('findAll');

        $formatter = new ProductFormatter(
            $this->router,
            ['en_US'],
            ['WEB_EU' => 'storefront', 'B2B' => 'wholesale'],
            '',
            'brand',
            null,
            $channelRepo,
        );

        $eurPricing = $this->createPricing(4999);
        $usdPricing = $this->createPricing(5499);

        $product = $this->createMultiChannelProduct(
            [$webChannel, $b2bChannel],
            [[$webChannel, $eurPricing], [$b2bChannel, $usdPricing]],
        );

        $events = $formatter->format($product);
        $d = $events[0]['data'];

        $this->assertSame(['storefront', 'wholesale'], $d['channels']);
        $this->assertSame('EUR', $d['prices']['storefront'][0]['currency']);
        $this->assertSame('USD', $d['prices']['wholesale'][0]['currency']);
    }

    /**
     * Auto-detection is cached — repository queried only once.
     */
    public function testAutoDetectIsCached(): void
    {
        $channel = $this->createChannel('MAIN', 'EUR', ['en_US']);
        $pricing = $this->createPricing(1000);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->expects($this->once())->method('findAll')->willReturn([$channel]);

        $formatter = new ProductFormatter(
            $this->router, ['en_US'], [], '', 'brand', null, $channelRepo,
        );

        $product = $this->createSimpleProduct($channel, $pricing);

        // Call format twice — findAll should only be called once
        $formatter->format($product);
        $formatter->format($product);
    }

    /**
     * No channel repository injected and no mapping — falls back to "" for all.
     */
    public function testNoRepositoryFallsBackToDefault(): void
    {
        $ch1 = $this->createChannel('CH_A', 'EUR', ['en_US']);
        $ch2 = $this->createChannel('CH_B', 'USD', ['en_US']);

        // No channel repository — null
        $formatter = new ProductFormatter($this->router, ['en_US'], []);

        $eurPricing = $this->createPricing(1000);
        $usdPricing = $this->createPricing(1200);

        $product = $this->createMultiChannelProduct(
            [$ch1, $ch2],
            [[$ch1, $eurPricing], [$ch2, $usdPricing]],
        );

        $events = $formatter->format($product);

        // Both collapse to "" (same as before)
        $this->assertSame([''], $events[0]['data']['channels']);
    }
}
