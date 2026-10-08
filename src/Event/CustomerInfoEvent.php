<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Event;

use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the Customer info action found the signed-in customer,
 * after `data` is filled and before it is signed and sent. Listeners may
 * change or remove any key, or add their own fields under `extra`.
 *
 * `data` keys Emporiqa reads: `customer` ({name, first_name, last_name,
 * email}), `orders` (at most 10, newest first, each {order_number,
 * placed_at, status_code, total, currency}; a `status_label` in your own
 * wording may be added) and `extra`
 * (string keys, values that are strings, numbers, booleans or nested
 * objects/lists, at most 3 levels deep, 30 keys in all, strings up to 500
 * characters). Other keys are dropped by Emporiqa.
 *
 * Never add addresses, phone numbers, internal ids or anything about
 * another customer: the answer is read by the chat for this shopper only.
 *
 *     public function onCustomerInfo(CustomerInfoEvent $event): void
 *     {
 *         $data = $event->getData();
 *         unset($data['customer']['email']);
 *         $data['extra']['loyalty_tier'] = 'gold';
 *         $event->setData($data);
 *     }
 */
class CustomerInfoEvent extends Event
{
    public const NAME = 'emporiqa.customer_info';

    public function __construct(
        private array $data,
        private CustomerInterface $customer,
    ) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }
}
