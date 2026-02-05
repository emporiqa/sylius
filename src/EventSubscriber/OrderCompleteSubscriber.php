<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Sends order.completed webhook when checkout completes.
 *
 * Requires Symfony Workflow (Sylius 2.x). On Sylius 1.x (Winzou State Machine),
 * the workflow event is never dispatched — the subscriber simply does not fire.
 */
class OrderCompleteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private RequestStack $requestStack,
        private ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.sylius_order_checkout.completed.complete' => ['onOrderComplete', 50],
        ];
    }

    public function onOrderComplete(CompletedEvent $event): void
    {
        $order = $event->getSubject();
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

        $this->webhookSender->send('order.completed', $data);

        $this->logger?->info('Sent order.completed webhook for order ' . $data['order_id']);
    }
}
