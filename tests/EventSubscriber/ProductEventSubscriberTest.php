<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventSubscriber;

use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\EventSubscriber\ProductEventSubscriber;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductEventSubscriberTest extends TestCase
{
    private WebhookEventQueue $webhookQueue;
    private ProductFormatterInterface $formatter;
    private LoggerInterface $logger;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->webhookQueue = new WebhookEventQueue($webhookSender);
        $this->formatter = $this->createMock(ProductFormatterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            fn ($event) => $event
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ProductEventSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('sylius.product.post_create', $events);
        $this->assertArrayHasKey('sylius.product.post_update', $events);
        $this->assertArrayHasKey('sylius.product.pre_delete', $events);
        $this->assertArrayHasKey('sylius.product_variant.post_create', $events);
        $this->assertArrayHasKey('sylius.product_variant.post_update', $events);
        $this->assertArrayHasKey('sylius.product_variant.pre_delete', $events);
    }

    public function testOnProductCreateQueuesEventsWithCreatedType(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnProductCreateSkipsWhenSyncDisabled(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, false, $this->logger);
        $subscriber->onProductCreate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }

    public function testOnProductUpdateQueuesEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductUpdate($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnProductDeleteQueuesDeleteEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('formatForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductDelete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testOnVariantDeleteQueuesDeleteEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getProduct')->willReturn($product);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($variant);

        $this->formatter->method('formatVariantForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'variation-10']],
        ]);

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onVariantDelete($event);

        $this->assertTrue($this->webhookQueue->hasPending());
    }

    public function testLogsErrorOnFormatterFailure(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willThrowException(new \RuntimeException('Format failed'));

        $this->logger->expects($this->once())->method('error');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $this->eventDispatcher);
        $subscriber->onProductCreate($event);
    }

    public function testIgnoresNonProductSubjects(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn(new \stdClass());

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger);
        $subscriber->onProductCreate($event);
    }

    public function testPreSyncEventCanCancelSync(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $cancellingDispatcher = $this->createMock(EventDispatcherInterface::class);
        $cancellingDispatcher->method('dispatch')->willReturnCallback(
            function ($event) {
                if ($event instanceof PreSyncEvent) {
                    $event->cancel();
                }
                return $event;
            }
        );

        $this->formatter->expects($this->never())->method('format');

        $subscriber = new ProductEventSubscriber($this->webhookQueue, $this->formatter, true, $this->logger, $cancellingDispatcher);
        $subscriber->onProductCreate($event);

        $this->assertFalse($this->webhookQueue->hasPending());
    }
}
