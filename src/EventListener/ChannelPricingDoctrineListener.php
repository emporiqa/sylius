<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Emporiqa\SyliusPlugin\EventSubscriber\ProductEventSubscriber;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Re-sends a product when one of its channel prices changes. Sylius applies
 * catalog promotions (on start, on end, on any edit) from a Messenger
 * handler that writes ChannelPricing directly, with no resource event, so
 * without this the synced price stayed stale until the next full sync.
 *
 * An admin product save also lands here; the queue builds each product once.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
class ChannelPricingDoctrineListener
{
    public function __construct(
        private ProductEventSubscriber $productEvents,
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $pricing = $args->getObject();
        if (!$pricing instanceof ChannelPricingInterface) {
            return;
        }
        $product = $pricing->getProductVariant()?->getProduct();
        if ($product instanceof ProductInterface) {
            $this->productEvents->onChannelPriceChanged($product);
        }
    }
}
