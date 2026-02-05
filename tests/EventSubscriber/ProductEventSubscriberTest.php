<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventSubscriber;

use Emporiqa\SyliusPlugin\EventSubscriber\ProductEventSubscriber;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

class ProductEventSubscriberTest extends TestCase
{
    private WebhookSenderInterface $webhookSender;
    private ProductFormatterInterface $formatter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->formatter = $this->createMock(ProductFormatterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
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

    public function testOnProductCreateSendsWebhook(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['id' => 1]],
        ]);

        $this->webhookSender
            ->expects($this->once())
            ->method('sendBatch')
            ->with($this->callback(function (array $events) {
                return $events[0]['type'] === 'product.created';
            }));

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onProductCreate($event);
    }

    public function testOnProductCreateSkipsWhenSyncDisabled(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);

        $this->webhookSender->expects($this->never())->method('sendBatch');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, false, $this->logger);
        $subscriber->onProductCreate($event);
    }

    public function testOnProductUpdateSendsWebhook(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['id' => 1]],
        ]);

        $this->webhookSender->expects($this->once())->method('sendBatch');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onProductUpdate($event);
    }

    public function testOnProductDeleteSendsDeleteEvents(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('formatForDeletion')->willReturn([
            ['type' => 'product.deleted', 'data' => ['identification_number' => 'product-1']],
        ]);

        $this->webhookSender->expects($this->once())->method('sendBatch');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onProductDelete($event);
    }

    public function testOnVariantDeleteSendsDeleteEvents(): void
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

        $this->webhookSender->expects($this->once())->method('sendBatch');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onVariantDelete($event);
    }

    public function testLogsErrorOnWebhookFailure(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($product);

        $this->formatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => []],
        ]);

        $this->webhookSender->method('sendBatch')->willThrowException(new \RuntimeException('Connection failed'));

        $this->logger->expects($this->once())->method('error');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onProductCreate($event);
    }

    public function testIgnoresNonProductSubjects(): void
    {
        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn(new \stdClass());

        $this->webhookSender->expects($this->never())->method('sendBatch');

        $subscriber = new ProductEventSubscriber($this->webhookSender, $this->formatter, true, $this->logger);
        $subscriber->onProductCreate($event);
    }
}
