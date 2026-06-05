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

    private function trackedVariant(int $id, string $code, int $onHand, int $onHold = 0, bool $enabled = true): ProductVariantInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getCode')->willReturn($code);
        $variant->method('isEnabled')->willReturn($enabled);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn($onHand);
        $variant->method('getOnHold')->willReturn($onHold);

        return $variant;
    }

    public function testTrackedInStockMultiVariantEmitsVariationAndParent(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getId')->willReturn(2);
        $product->method('getCode')->willReturn('PROD');
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $saved = $this->trackedVariant(10, 'SKU-RED', 8, 3);
        $sibling = $this->trackedVariant(11, 'SKU-BLUE', 0, 0);
        $product->method('getVariants')->willReturn(new ArrayCollection([$saved, $sibling]));
        $saved->method('getProduct')->willReturn($product);

        $events = $this->formatter->format($saved);

        // Multi-variant: one variation event PLUS the re-aggregated parent.
        $this->assertCount(2, $events);

        $this->assertSame('product.availability', $events[0]['type']);
        $this->assertSame('variation-10', $events[0]['data']['identification_number']);
        $this->assertSame('SKU-RED', $events[0]['data']['sku']);
        $this->assertSame(['WEB' => 'available'], $events[0]['data']['availability_statuses']);
        $this->assertSame(['WEB' => 5], $events[0]['data']['stock_quantities']);

        // Parent: available because at least one variant (the saved one) is
        // in stock; stock is null on the parent.
        $this->assertSame('product-2', $events[1]['data']['identification_number']);
        $this->assertSame(['WEB' => 'available'], $events[1]['data']['availability_statuses']);
        $this->assertSame(['WEB' => null], $events[1]['data']['stock_quantities']);
    }

    public function testLastVariantSelloutMarksParentOutOfStock(): void
    {
        // The exact gap this fix closes: the saved variant goes to zero and
        // every other variant is already out of stock, so the parent's stored
        // availability must flip to out_of_stock via the lightweight path —
        // not stay 'available' until the next full sync.
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('getId')->willReturn(3);
        $product->method('getCode')->willReturn('PROD3');
        $product->method('getChannels')->willReturn(new ArrayCollection([$this->channel('WEB')]));

        $saved = $this->trackedVariant(20, 'SKU-A', 0, 0);
        $sibling = $this->trackedVariant(21, 'SKU-B', 0, 0);
        $product->method('getVariants')->willReturn(new ArrayCollection([$saved, $sibling]));
        $saved->method('getProduct')->willReturn($product);

        $events = $this->formatter->format($saved);

        $this->assertCount(2, $events);
        $this->assertSame('variation-20', $events[0]['data']['identification_number']);
        $this->assertSame(['WEB' => 'out_of_stock'], $events[0]['data']['availability_statuses']);
        $this->assertSame('product-3', $events[1]['data']['identification_number']);
        $this->assertSame(['WEB' => 'out_of_stock'], $events[1]['data']['availability_statuses']);
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

        $events = $this->formatter->format($variant);

        // Single variant → exactly one event, under product-{id}, no parent.
        $this->assertCount(1, $events);
        $this->assertSame('product-99', $events[0]['data']['identification_number']);
        $this->assertSame(['WEB' => 4], $events[0]['data']['stock_quantities']);
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

        $events = $this->formatter->format($variant);

        $this->assertSame(['WEB' => null], $events[0]['data']['stock_quantities']);
        $this->assertSame(['WEB' => 'available'], $events[0]['data']['availability_statuses']);
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

        $events = $this->formatter->format($variant);

        $this->assertSame(['WEB' => 'out_of_stock'], $events[0]['data']['availability_statuses']);
        $this->assertSame(['WEB' => 0], $events[0]['data']['stock_quantities']);
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

        $events = $this->formatter->format($variant);

        $this->assertSame(['WEB' => 'out_of_stock'], $events[0]['data']['availability_statuses']);
    }

    public function testNoChannelsReturnsEmpty(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getChannels')->willReturn(new ArrayCollection([]));

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn($product);

        $this->assertSame([], $this->formatter->format($variant));
    }
}
