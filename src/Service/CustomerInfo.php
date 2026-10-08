<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Event\CustomerInfoEvent;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The `customer_info` ready-made action: who the signed-in shopper is, read
 * only. The customer id comes from a customer token Emporiqa verified for
 * this store, never from a shopper's message.
 *
 * The answer is the account's name and email and its newest placed orders
 * (never carts) in the channels this plugin syncs. Orders a guest checkout
 * placed with the account's email are left out: Sylius keeps one customer
 * per email, so they sit on the same customer record, and only the order's
 * createdByGuest flag tells them apart (the proof rule Order status uses).
 * There is no status_label: Sylius has no store wording for its order
 * states, and the order state ("new") would contradict the status_code
 * derived from payment and shipping, as Order status leaves it out too. An unknown id, or a customer without an enabled
 * shop account (a guest record), is `not_found`.
 *
 * Nothing else leaves the shop: no addresses, phone, group or internal ids.
 * `test: true` (the owner's test panel) is answered the same way; the
 * lookup has no side effects to skip.
 */
class CustomerInfo
{
    public const MAX_ORDERS = 10;

    private const MAX_TEXT = 200;

    public function __construct(
        private CustomerRepositoryInterface $customers,
        private OrderRepositoryInterface $orders,
        private ChannelMappingResolver $channels,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload): array
    {
        $customerId = CustomerPrices::customerId($payload);
        if ($customerId === '') {
            return ['status' => 'rejected', 'message_code' => 'missing_field'];
        }

        $customer = $this->customers->find((int) $customerId);
        $user = $customer instanceof CustomerInterface ? $customer->getUser() : null;
        if (!$customer instanceof CustomerInterface || !$user instanceof ShopUserInterface || !$user->isEnabled()) {
            return ['status' => 'not_found'];
        }

        $data = [
            'customer' => array_filter([
                'name' => self::text($customer->getFullName()),
                'first_name' => self::text($customer->getFirstName()),
                'last_name' => self::text($customer->getLastName()),
                'email' => self::text($customer->getEmail()),
            ], static fn (string $value): bool => $value !== ''),
            'orders' => $this->ordersPart($customer),
        ];

        if ($this->eventDispatcher !== null) {
            $event = new CustomerInfoEvent($data, $customer);
            $this->eventDispatcher->dispatch($event, CustomerInfoEvent::NAME);
            $data = $event->getData();
        }

        return ['status' => 'found', 'data' => $data];
    }

    /**
     * Placed orders of this customer, newest first, at most MAX_ORDERS. The
     * query already asks for exactly that; every condition is checked again
     * here, so a decorated repository can never hand over a cart or another
     * customer's order.
     *
     * @return list<array>
     */
    private function ordersPart(CustomerInterface $customer): array
    {
        $channelCodes = $this->channels->getAllKeys();
        if ($channelCodes === []) {
            return [];
        }

        $orders = [];
        foreach ($this->loadPlacedOrders($customer, $channelCodes) as $order) {
            if (!$order instanceof OrderInterface ||
                $order->getCustomer()?->getId() !== $customer->getId() ||
                $order->getCheckoutCompletedAt() === null ||
                $order->getState() === OrderInterface::STATE_CART ||
                OrderStatusLookup::placedAsGuest($order) ||
                !in_array($order->getChannel()?->getCode(), $channelCodes, true)
            ) {
                continue;
            }

            try {
                $orders[] = $this->orderSummary($order);
            } catch (\Throwable $e) {
                // The class only: a message can quote the customer's data.
                $this->logger?->warning('Emporiqa customer_info left out an order', ['exception_class' => $e::class]);
            }
        }
        usort($orders, static fn (array $a, array $b): int => strcmp($b['placed_at'], $a['placed_at']));

        return array_slice($orders, 0, self::MAX_ORDERS);
    }

    /**
     * @param list<string> $channelCodes
     *
     * @return iterable<mixed>
     */
    protected function loadPlacedOrders(CustomerInterface $customer, array $channelCodes): iterable
    {
        $query = $this->orders->createListQueryBuilder();
        if ($query->getEntityManager()->getClassMetadata($this->orders->getClassName())->hasField('createdByGuest')) {
            $query->andWhere('o.createdByGuest = false');
        }

        return $query
            ->innerJoin('o.channel', 'emporiqa_channel')
            ->andWhere('o.customer = :emporiqaCustomer')
            ->andWhere('o.checkoutCompletedAt IS NOT NULL')
            ->andWhere('emporiqa_channel.code IN (:emporiqaChannels)')
            ->setParameter('emporiqaCustomer', $customer->getId())
            ->setParameter('emporiqaChannels', $channelCodes)
            ->orderBy('o.checkoutCompletedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(self::MAX_ORDERS)
            ->getQuery()
            ->getResult();
    }

    private function orderSummary(OrderInterface $order): array
    {
        $currency = strtoupper(self::text($order->getCurrencyCode()));

        return array_filter([
            'order_number' => self::text($order->getNumber()),
            'placed_at' => (string) $order->getCheckoutCompletedAt()?->format('c'),
            'status_code' => OrderStatusLookup::statusCode($order),
            'total' => CurrencyHelper::toCurrencyUnits($order->getTotal(), $currency),
            'currency' => $currency,
        ], static fn ($value): bool => $value !== '');
    }

    private static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $value)), 0, self::MAX_TEXT);
    }
}
