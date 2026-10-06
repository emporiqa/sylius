<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Emporiqa\SyliusPlugin\EventListener\ChannelPricingDoctrineListener;
use Emporiqa\SyliusPlugin\EventSubscriber\ProductEventSubscriber;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * A catalog promotion starting or ending rewrites channel prices from a
 * Messenger handler, with no resource event; the product must still be sent.
 */
class ChannelPricingDoctrineListenerTest extends TestCase
{
    private function args(object $entity): PostUpdateEventArgs
    {
        return new PostUpdateEventArgs($entity, $this->createMock(EntityManagerInterface::class));
    }

    public function testChannelPriceChangeQueuesTheProduct(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn($product);
        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getProductVariant')->willReturn($variant);

        $sender = $this->createMock(WebhookSenderInterface::class);
        $sender->expects($this->once())->method('sendBatch')
            ->with([['type' => 'product.updated', 'data' => ['identification_number' => 'product-5']]])
            ->willReturn(true);
        $formatter = $this->createMock(ProductFormatterInterface::class);
        $formatter->expects($this->once())->method('format')->with($product)
            ->willReturn([['type' => 'product.updated', 'data' => ['identification_number' => 'product-5']]]);
        $queue = new WebhookEventQueue($sender);
        $listener = new ChannelPricingDoctrineListener(new ProductEventSubscriber($queue, $formatter));

        // Two prices of one product in one message: built and sent once.
        $listener->postUpdate($this->args($pricing));
        $listener->postUpdate($this->args($pricing));
        $queue->flush();
    }

    public function testOtherEntitiesAndDisabledSyncAreIgnored(): void
    {
        $queue = new WebhookEventQueue($this->createMock(WebhookSenderInterface::class));
        $formatter = $this->createMock(ProductFormatterInterface::class);

        (new ChannelPricingDoctrineListener(new ProductEventSubscriber($queue, $formatter)))
            ->postUpdate($this->args(new \stdClass()));

        $product = $this->createMock(ProductInterface::class);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn($product);
        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getProductVariant')->willReturn($variant);
        (new ChannelPricingDoctrineListener(new ProductEventSubscriber($queue, $formatter, false)))
            ->postUpdate($this->args($pricing));

        $this->assertFalse($queue->hasPending());
    }
}
