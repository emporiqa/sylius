<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
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
            ['en_US'],
            [],
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
        $this->assertSame('Test Product', $events[0]['data']['names']['']['en']);
        $this->assertSame('A test product', $events[0]['data']['descriptions']['']['en']);
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

        $option = $this->createMock(ProductOptionInterface::class);
        $option->method('getCode')->willReturn('size');

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
        $this->assertSame(['size'], $events[0]['data']['variation_attributes']);
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
        $formatter = new ProductFormatter($this->router, ['en_US', 'de_DE'], []);

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
        $this->assertSame('Product', $events[0]['data']['names']['']['en']);
        $this->assertSame('Produkt', $events[0]['data']['names']['']['de']);
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

    public function testFormatWithChannelMapping(): void
    {
        $formatter = new ProductFormatter(
            $this->router,
            ['en_US'],
            ['WEB' => '', 'B2B' => 'b2b'],
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
}
