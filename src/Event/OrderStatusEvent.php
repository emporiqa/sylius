<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the Order status rule found an order, after the answer's
 * `data` is filled and before it is signed and sent. Listeners may change
 * any key (e.g. add a carrier tracking link or an `estimated_delivery` date
 * from a shipping plugin) or remove one.
 *
 * `data` keys Emporiqa reads: status_code, status_label, placed_at,
 * tracking, estimated_delivery, order_number, customer_name, currency,
 * items, totals, payment_method, payment_status, shipping_method,
 * delivery_time, shipping_address, billing_address and extra. Other keys
 * are dropped by Emporiqa.
 *
 * Put your own fields under `extra`: string keys, values that are strings,
 * numbers, booleans, or nested objects/lists, at most 3 levels deep, 30 keys
 * in all, strings up to 500 characters. The chat shows them when the
 * shopper asks for details. Never put the shopper's email, ids or other
 * personal data there.
 *
 *     public function onOrderStatus(OrderStatusEvent $event): void
 *     {
 *         $data = $event->getData();
 *         $data['extra']['gift_wrap'] = true;
 *         $data['extra']['loyalty_points'] = 120;
 *         $event->setData($data);
 *     }
 */
class OrderStatusEvent extends Event
{
    public const NAME = 'emporiqa.order_status';

    public function __construct(
        private array $data,
        private OrderInterface $order,
    ) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getOrder(): OrderInterface
    {
        return $this->order;
    }
}
