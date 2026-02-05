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
        if (!$order instanceof OrderInterface) {
            return null;
        }

        if (isset($verificationFields['email'])) {
            if ($order->getCustomer()?->getEmail() !== $verificationFields['email']) {
                return null;
            }
        }

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'name' => $item->getProductName() ?? $item->getVariantName(),
                'quantity' => $item->getQuantity(),
                'price' => $item->getUnitPrice() / 100,
            ];
        }

        $shipping = $this->resolveShippingInfo($order);

        return [
            'order_id' => $order->getNumber(),
            'status' => $this->resolveOrderStatus($order),
            'placed_at' => $order->getCheckoutCompletedAt()?->format('c'),
            'items' => $items,
            'shipping' => $shipping,
            'total' => $order->getTotal() / 100,
            'currency' => $order->getCurrencyCode(),
        ];
    }

    private function resolveOrderStatus(OrderInterface $order): string
    {
        if ($order->getPaymentState() === OrderPaymentStates::STATE_AWAITING_PAYMENT) {
            return 'pending_payment';
        }

        if ($order->getShippingState() === OrderShippingStates::STATE_SHIPPED) {
            return 'shipped';
        }

        if ($order->getShippingState() === OrderShippingStates::STATE_PARTIALLY_SHIPPED) {
            return 'partially_shipped';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_PAID) {
            return 'processing';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_REFUNDED) {
            return 'refunded';
        }

        if ($order->getPaymentState() === OrderPaymentStates::STATE_CANCELLED) {
            return 'cancelled';
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
