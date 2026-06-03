<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\VariantStockFormatter;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

class VariantStockFormatterTest extends TestCase
{
    private VariantStockFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new VariantStockFormatter(new ChannelMappingResolver());
    }

    private function channel(string $code): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        return $channel;
    }

    public function testTrackedInStockMultiVariantUsesVariationId(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getCode')->willReturn('SKU-RED');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(8);
        $variant->method('getOnHold')->willReturn(3);

        // Multi-variant product (count > 1).
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant, clone $variant]));

        $event = $this->formatter->format($variant);

        $this->assertNotNull($event);
        $this->assertSame('product.availability', $event['type']);
        $this->assertSame('variation-10', $event['data']['identification_number']);
        $this->assertSame('SKU-RED', $event['data']['sku']);
        $this->assertSame(['WEB' => 'available'], $event['data']['availability_statuses']);
        $this->assertSame(['WEB' => 5], $event['data']['stock_quantities']);
    }

    public function testSimpleProductUsesProductId(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getId')->willReturn(99);
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SKU-1');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(4);
        $variant->method('getOnHold')->willReturn(0);

        // Single-variant product.
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $event = $this->formatter->format($variant);

        $this->assertSame('product-99', $event['data']['identification_number']);
        $this->assertSame(['WEB' => 4], $event['data']['stock_quantities']);
    }

    public function testUntrackedVariantReportsNullQtyAndAvailable(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getId')->willReturn(5);
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SKU-U');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $event = $this->formatter->format($variant);

        $this->assertSame(['WEB' => null], $event['data']['stock_quantities']);
        $this->assertSame(['WEB' => 'available'], $event['data']['availability_statuses']);
    }

    public function testTrackedZeroStockIsOutOfStock(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getId')->willReturn(7);
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SKU-Z');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(2);
        $variant->method('getOnHold')->willReturn(2);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $event = $this->formatter->format($variant);

        $this->assertSame(['WEB' => 'out_of_stock'], $event['data']['availability_statuses']);
        $this->assertSame(['WEB' => 0], $event['data']['stock_quantities']);
    }

    public function testDisabledProductIsOutOfStock(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(false);
        $product->method('getId')->willReturn(8);
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('SKU-D');
        $variant->method('getProduct')->willReturn($product);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(50);
        $variant->method('getOnHold')->willReturn(0);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $event = $this->formatter->format($variant);

        $this->assertSame(['WEB' => 'out_of_stock'], $event['data']['availability_statuses']);
    }

    public function testNoChannelsReturnsNull(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getChannels')->willReturn(new ArrayCollection([]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn($product);

        $this->assertNull($this->formatter->format($variant));
    }
}
