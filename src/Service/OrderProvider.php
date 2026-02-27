<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;

class OrderProvider implements OrderProviderInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {}

    public function findOrder(string $identifier, ?string $userId, array $verificationFields): ?array
    {
        $order = $this->orderRepository->findOneByNumber($identifier);

        // Sylius pads order numbers with leading zeros by default (e.g. "000000003").
        // If the exact match fails and the identifier is numeric, retry with zero-padding.
        if (!$order instanceof OrderInterface && ctype_digit($identifier)) {
            $padded = str_pad($identifier, 9, '0', STR_PAD_LEFT);
            if ($padded !== $identifier) {
                $order = $this->orderRepository->findOneByNumber($padded);
            }
        }

        if (!$order instanceof OrderInterface) {
            return null;
        }

        if (isset($verificationFields['email'])) {
            $orderEmail = $order->getCustomer()?->getEmail();
            if ($orderEmail === null || mb_strtolower($orderEmail) !== mb_strtolower($verificationFields['email'])) {
                return null;
            }
        }

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'name' => $item->getProductName() ?? $item->getVariantName(),
                'quantity' => $item->getQuantity(),
                'price' => round($item->getUnitPrice() / 100, 2),
            ];
        }

        $shipping = $this->resolveShippingInfo($order);

        return [
            'order_id' => $order->getNumber(),
            'status' => $this->resolveOrderStatus($order),
            'placed_at' => $order->getCheckoutCompletedAt()?->format('c'),
            'items' => $items,
            'shipping' => $shipping,
            'total' => round($order->getTotal() / 100, 2),
            'currency' => $order->getCurrencyCode(),
        ];
    }

    private function resolveOrderStatus(OrderInterface $order): string
    {
        // Terminal payment states first — these override everything
        if ($order->getPaymentState() === OrderPaymentStates::STATE_CANCELLED) {
            return 'cancelled';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_REFUNDED) {
            return 'refunded';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_AWAITING_PAYMENT) {
            return 'pending_payment';
        }

        // Shipping states
        if ($order->getShippingState() === OrderShippingStates::STATE_SHIPPED) {
            return 'shipped';
        }

        if ($order->getShippingState() === OrderShippingStates::STATE_PARTIALLY_SHIPPED) {
            return 'partially_shipped';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_PAID) {
            return 'processing';
        }

        return 'processing';
    }

    private function resolveShippingInfo(OrderInterface $order): ?array
    {
        $shipments = $order->getShipments();
        if ($shipments->isEmpty()) {
            return null;
        }

        /** @var ShipmentInterface $shipment */
        $shipment = $shipments->first();

        return [
            'method' => $shipment->getMethod()?->getName(),
            'tracking_number' => $shipment->getTracking(),
            'state' => $shipment->getState(),
        ];
    }
}
