<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventSubscriber;

use Emporiqa\SyliusPlugin\Service\CurrencyHelper;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Queues order.completed webhook when checkout completes, and sends again one
 * Emporiqa refused when the order's payment reaches paid.
 *
 * Subscribes to both:
 * - Symfony Workflow events (Sylius 2.x, and 1.13 configured for workflow)
 * - Winzou State Machine events (Sylius 1.x)
 *
 * Winzou dispatches one generic post-transition event for every graph and
 * transition, with the order on the state machine rather than a subject, so
 * that handler filters by graph and transition itself.
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
            'workflow.sylius_order_payment.completed.pay' => ['onOrderPaid', 50],
            // Sylius 1.x — Winzou State Machine
            'winzou.state_machine.post_transition' => ['onWinzouTransition', 50],
        ];
    }

    public function onWinzouTransition(object $event): void
    {
        if (!method_exists($event, 'getStateMachine') || !method_exists($event, 'getTransition')) {
            return;
        }
        $stateMachine = $event->getStateMachine();
        $order = $stateMachine->getObject();
        if (!$order instanceof OrderInterface) {
            return;
        }
        $route = $stateMachine->getGraph() . '.' . $event->getTransition();
        if ($route === 'sylius_order_checkout.complete') {
            $this->queueCompleted($order);
        } elseif ($route === 'sylius_order_payment.pay') {
            $this->retryRefused($order);
        }
    }

    public function onOrderPaid(object $event): void
    {
        $order = method_exists($event, 'getSubject') ? $event->getSubject() : null;
        if ($order instanceof OrderInterface) {
            $this->retryRefused($order);
        }
    }

    private function retryRefused(OrderInterface $order): void
    {
        $this->webhookQueue->retryRefusedOrder((string) ($order->getNumber() ?? $order->getId()));
    }

    public function onOrderComplete(object $event): void
    {
        $order = method_exists($event, 'getSubject') ? $event->getSubject() : null;
        if ($order instanceof OrderInterface) {
            $this->queueCompleted($order);
        }
    }

    private function queueCompleted(OrderInterface $order): void
    {
        try {
            $this->sendOrderCompletedWebhook($order);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send order.completed webhook: ' . $e->getMessage());
        }
    }

    private function sendOrderCompletedWebhook(OrderInterface $order): void
    {
        // Payment-state gate: skip cancelled-payment orders so we don't
        // over-attribute orders that ultimately failed at the PSP.
        // Sylius `sylius_order_checkout.complete` fires BEFORE payment
        // is confirmed for most flows (Stripe/PayPal complete payment
        // in the same flow → state is `awaiting_payment` then `paid`;
        // bank-transfer orders stay `awaiting_payment` until admin
        // marks them paid; cancelled orders move to `cancelled`).
        // Skipping only the explicit cancelled state is the minimal-
        // regression posture — the bulk of current production
        // attribution (orders that complete checkout AND pay shortly
        // after) continues to attribute. Future improvement: move
        // emission to the payment.pay state-machine event and
        // persist the cookie at checkout time.
        $paymentState = $order->getPaymentState();
        if ($paymentState === 'cancelled') {
            $this->logger?->info(
                'Skipping order.completed webhook for cancelled-payment order '
                . ((string) ($order->getNumber() ?? $order->getId()))
            );
            return;
        }

        $currencyCode = $order->getCurrencyCode() ?? '';

        $items = [];
        foreach ($order->getItems() as $orderItem) {
            $variant = $orderItem->getVariant();

            // Null-safe — Sylius `getUnitPrice()` returns ?int and on a
            // partially-built order can be null. Without the coalesce
            // the CurrencyHelper::toCurrencyUnits int-typed signature
            // raises TypeError, the outer try/catch in onOrderComplete
            // swallows it, and the webhook silently disappears.
            $items[] = [
                'product_id' => $variant ? 'variation-' . $variant->getId() : '',
                'quantity' => $orderItem->getQuantity(),
                'price' => CurrencyHelper::toCurrencyUnits(
                    (int) ($orderItem->getUnitPrice() ?? 0),
                    $currencyCode,
                ),
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
            'total' => CurrencyHelper::toCurrencyUnits(
                (int) ($order->getTotal() ?? 0),
                $currencyCode,
            ),
            'currency' => $currencyCode,
            'emporiqa_session_id' => $sessionId,
            'items' => $items,
        ];

        $this->webhookQueue->queue([['type' => 'order.completed', 'data' => $data]]);

        $this->logger?->info(
            sprintf(
                'Queued order.completed webhook for order %s (payment_state=%s)',
                $data['order_id'],
                $paymentState ?? 'unknown',
            )
        );
    }
}
