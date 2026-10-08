<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Event\PostFormatEvent;
use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Model\DeletedItem;
use Emporiqa\SyliusPlugin\Model\PageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The current state of products, variations and pages, read from the
 * database and formatted as the live sync formats them (PreSyncEvent and
 * PostFormatEvent included):
 *
 * - product-{id}: the product's events (its deletion when it is disabled);
 *   its deletion when it no longer exists.
 * - variation-{id}: the events of the variant's product, plus the deletion
 *   of that variation row when the product has one variant left (it is sent
 *   as a simple product then); its deletion when the variant is gone.
 * - page-{id}: the page's events; its deletion when no page class has it.
 *
 * A deletion of an item that is gone dispatches PreSyncEvent('delete') with
 * a DeletedItem as the entity, so a listener's cancel is honoured on retry.
 * An item whose state cannot be read is left out and reported in $failed;
 * the others are still built.
 */
class CurrentStateBuilder implements CurrentStateBuilderInterface
{
    /** @param list<string> $pageEntityClasses */
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductVariantRepositoryInterface $variants,
        private ProductFormatterInterface $productFormatter,
        private ?PageFormatterInterface $pageFormatter,
        private EntityManagerInterface $entityManager,
        private array $pageEntityClasses = [],
        private bool $syncProducts = true,
        private bool $syncPages = true,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function build(array $identificationNumbers, array &$failed = []): array
    {
        $failed = [];
        $events = [];
        $productsDone = [];
        foreach (array_unique($identificationNumbers) as $ident) {
            if (preg_match('/^(product|variation|page)-(\d{1,18})$/D', $ident, $m) !== 1) {
                continue;
            }

            // One item that cannot be read must not hold up the others.
            try {
                array_push($events, ...$this->item($m[1], (int) $m[2], $ident, $productsDone));
            } catch (\Throwable $e) {
                $failed[] = $ident;
            }
        }

        return $events;
    }

    /**
     * @param array<int, true> $productsDone products already built in this call
     *
     * @return list<array>
     */
    private function item(string $type, int $id, string $ident, array &$productsDone): array
    {
        if ($type === 'page') {
            return $this->syncPages ? $this->page($id) : [];
        }
        if (!$this->syncProducts) {
            return [];
        }

        if ($type === 'product') {
            $product = $this->products->find($id);
            if (!$product instanceof ProductInterface) {
                return $this->deletion('product', $id);
            }
            if (isset($productsDone[$id])) {
                return [];
            }
            $productsDone[$id] = true;

            return $this->product($product);
        }

        $variant = $this->variants->find($id);
        $product = $variant instanceof ProductVariantInterface ? $variant->getProduct() : null;
        if (!$variant instanceof ProductVariantInterface || !$product instanceof ProductInterface) {
            return $this->deletion('variation', $id);
        }
        $events = [];
        if ($product->getVariants()->count() <= 1) {
            $events[] = self::deleted($ident);
        }
        $productId = (int) $product->getId();
        if (!isset($productsDone[$productId])) {
            $productsDone[$productId] = true;
            array_push($events, ...$this->product($product));
        }

        return $events;
    }

    /**
     * The deletion of an item that is gone, unless a PreSyncEvent listener
     * cancels it, as it could when the item was deleted.
     *
     * @return list<array>
     */
    private function deletion(string $type, int $id): array
    {
        $item = new DeletedItem($type, $id);
        if ($this->eventDispatcher !== null) {
            $event = new PreSyncEvent($item, $type, 'delete');
            $this->eventDispatcher->dispatch($event, PreSyncEvent::NAME);
            if ($event->isCancelled()) {
                return [];
            }
        }

        return [[
            'type' => $type === 'page' ? 'page.deleted' : 'product.deleted',
            'data' => ['identification_number' => $item->getIdentificationNumber()],
        ]];
    }

    /** @return list<array> */
    private function product(ProductInterface $product): array
    {
        if ($this->cancelled($product, 'product')) {
            return [];
        }
        $events = $this->productFormatter->format($product);
        if ($this->eventDispatcher !== null) {
            $event = new PostFormatEvent($events, $product);
            $this->eventDispatcher->dispatch($event, PostFormatEvent::NAME);
            $events = $event->getFormattedEvents();
        }

        return array_values($events);
    }

    /** @return list<array> */
    private function page(int $id): array
    {
        // No formatter when no page class is configured (the extension removes it).
        if ($this->pageFormatter === null) {
            return [];
        }
        foreach ($this->pageEntityClasses as $class) {
            $page = $this->entityManager->find($class, $id);
            if ($page instanceof PageInterface) {
                return $this->cancelled($page, 'page') ? [] : array_values($this->pageFormatter->format($page));
            }
        }

        return $this->pageEntityClasses === [] ? [] : $this->deletion('page', $id);
    }

    private function cancelled(object $entity, string $type): bool
    {
        if ($this->eventDispatcher === null) {
            return false;
        }
        $event = new PreSyncEvent($entity, $type, 'update');
        $this->eventDispatcher->dispatch($event, PreSyncEvent::NAME);

        return $event->isCancelled();
    }

    private static function deleted(string $ident): array
    {
        return ['type' => 'product.deleted', 'data' => ['identification_number' => $ident]];
    }
}
