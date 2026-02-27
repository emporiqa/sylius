<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Queues order.completed webhook when checkout completes.
 *
 * Subscribes to both:
 * - Symfony Workflow events (Sylius 2.x)
 * - Winzou State Machine events (Sylius 1.x)
 *
 * Only the active state machine engine will dispatch events;
 * the other subscription is simply never triggered.
 */
class OrderCompleteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private RequestStack $requestStack,
        private ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Sylius 2.x — Symfony Workflow
            'workflow.sylius_order_checkout.completed.complete' => ['onOrderComplete', 50],
            // Sylius 1.x — Winzou State Machine
            'winzou.state_machine.sylius_order_checkout.post_transition.complete' => ['onOrderComplete', 50],
        ];
    }

    public function onOrderComplete(object $event): void
    {
        $order = method_exists($event, 'getSubject') ? $event->getSubject() : null;
        if (!$order instanceof OrderInterface) {
            return;
        }

        try {
            $this->sendOrderCompletedWebhook($order);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send order.completed webhook: ' . $e->getMessage());
        }
    }

    private function sendOrderCompletedWebhook(OrderInterface $order): void
    {
        $items = [];
        foreach ($order->getItems() as $orderItem) {
            $variant = $orderItem->getVariant();

            $items[] = [
                'product_id' => $variant ? (string) $variant->getId() : '',
                'quantity' => $orderItem->getQuantity(),
                'price' => round($orderItem->getUnitPrice() / 100, 2),
            ];
        }

        $sessionId = '';
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $raw = (string) $request->cookies->get('emporiqa_sid', '');
            $raw = mb_substr($raw, 0, 256);
            if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $raw)) {
                $sessionId = $raw;
            }
        }

        $data = [
            'order_id' => (string) ($order->getNumber() ?? $order->getId()),
            'total' => round($order->getTotal() / 100, 2),
            'currency' => $order->getCurrencyCode() ?? '',
            'emporiqa_session_id' => $sessionId,
            'items' => $items,
        ];

        $this->webhookQueue->queue([['type' => 'order.completed', 'data' => $data]]);

        $this->logger?->info('Queued order.completed webhook for order ' . $data['order_id']);
    }
}
