<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Event\PostFormatEvent;
use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Model\DeletedItem;
use Emporiqa\SyliusPlugin\Service\CurrentStateBuilder;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CurrentStateBuilderTest extends TestCase
{
    /** @var array<int, Product|\Throwable> */
    private array $products = [];

    /** @var array<int, ProductVariant> */
    private array $variants = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    private function builder(array $pageClasses = []): CurrentStateBuilder
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('find')->willReturnCallback(function ($id) {
            $found = $this->products[$id] ?? null;
            if ($found instanceof \Throwable) {
                throw $found;
            }

            return $found;
        });
        $variants = $this->createMock(ProductVariantRepositoryInterface::class);
        $variants->method('find')->willReturnCallback(fn ($id) => $this->variants[$id] ?? null);
        $formatter = $this->createMock(ProductFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(fn (ProductInterface $p): array => [
            ['type' => 'product.updated', 'data' => ['identification_number' => 'product-' . $p->getId(), 'name' => $p->getCode()]],
        ]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        return new CurrentStateBuilder($products, $variants, $formatter, $this->createMock(PageFormatterInterface::class), $em, $pageClasses, true, true, $this->dispatcher);
    }

    private function product(int $id, int $variants = 1): Product
    {
        $product = new Product();
        (new \ReflectionProperty($product, 'id'))->setValue($product, $id);
        $product->setCode('P' . $id);
        for ($i = 1; $i <= $variants; ++$i) {
            $variant = new ProductVariant();
            (new \ReflectionProperty($variant, 'id'))->setValue($variant, $id * 10 + $i);
            $product->addVariant($variant);
            $this->variants[$id * 10 + $i] = $variant;
        }

        return $this->products[$id] = $product;
    }

    public function testAProductIsBuiltAsItIsNowAndThroughPostFormatEvent(): void
    {
        $this->product(1);
        $this->dispatcher->addListener(PostFormatEvent::NAME, function (PostFormatEvent $event): void {
            $events = $event->getFormattedEvents();
            $events[0]['data']['touched'] = true;
            $event->setFormattedEvents($events);
        });

        $events = $this->builder()->build(['product-1']);

        $this->assertSame([['type' => 'product.updated', 'data' => ['identification_number' => 'product-1', 'name' => 'P1', 'touched' => true]]], $events);
    }

    public function testAGoneProductIsSentAsDeleted(): void
    {
        $this->assertSame([['type' => 'product.deleted', 'data' => ['identification_number' => 'product-9']]], $this->builder()->build(['product-9']));
    }

    /** Review point 5: a retried deletion honours a PreSyncEvent('delete') cancel. */
    public function testARetriedDeletionDispatchesPreSyncEventAndCanBeCancelled(): void
    {
        $seen = null;
        $this->dispatcher->addListener(PreSyncEvent::NAME, function (PreSyncEvent $event) use (&$seen): void {
            $seen = $event;
            if ($event->getOperation() === 'delete') {
                $event->cancel();
            }
        });

        $this->assertSame([], $this->builder()->build(['variation-9']));
        $this->assertInstanceOf(DeletedItem::class, $seen->getEntity());
        $this->assertSame('variation-9', $seen->getEntity()->getIdentificationNumber());
        $this->assertSame('variation', $seen->getEntityType());
    }

    /** A variation of a product now down to one variant: its row is deleted, the product sent as simple. */
    public function testAVariationOfAProductLeftWithOneVariant(): void
    {
        $this->product(2, 1);

        $events = $this->builder()->build(['variation-21']);

        $this->assertSame(['product.deleted:variation-21', 'product.updated:product-2'], array_map(fn ($e) => $e['type'] . ':' . $e['data']['identification_number'], $events));
    }

    public function testSeveralItemsOfOneProductBuildItOnce(): void
    {
        $this->product(3, 2);

        $events = $this->builder()->build(['variation-31', 'variation-32', 'product-3']);

        $this->assertCount(1, $events);
    }

    /** Review point 3: one item that cannot be read is left out and reported; the rest are built. */
    public function testOneItemThatThrowsIsReportedAndTheOthersAreBuilt(): void
    {
        $this->products[4] = new \RuntimeException('broken relation');
        $this->product(5);
        $failed = [];

        $events = $this->builder()->build(['product-4', 'product-5'], $failed);

        $this->assertSame(['product-4'], $failed);
        $this->assertSame(['product-5'], array_map(fn ($e) => $e['data']['identification_number'], $events));
    }

    public function testAGonePageIsSentAsDeleted(): void
    {
        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-7']]], $this->builder([\stdClass::class])->build(['page-7']));
    }

    public function testUnknownIdentsAreIgnored(): void
    {
        $this->assertSame([], $this->builder()->build(['order-1', 'product-x', 'variation-1-1']));
    }
}
