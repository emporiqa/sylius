<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Emporiqa\SyliusPlugin\Event\OrderTrackingEvent;
use Emporiqa\SyliusPlugin\Service\OrderProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderTrackingController
{
    public function __construct(
        private string $webhookSecret,
        private OrderProviderInterface $orderProvider,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function track(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->headers->get('X-Emporiqa-Signature', '');

        if (!$this->verifySignature($rawBody, $signature)) {
            return new JsonResponse(
                ['error' => 'Invalid signature'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || empty($payload['order_identifier']) || !isset($payload['timestamp'])) {
            return new JsonResponse(
                ['error' => 'Invalid request body'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $orderIdentifier = $payload['order_identifier'];
        $userId = $payload['user_id'] ?? null;
        $verificationFields = $payload['verification_fields'] ?? [];

        $orderData = $this->orderProvider->findOrder($orderIdentifier, $userId, $verificationFields);

        if ($this->eventDispatcher) {
            $trackingEvent = new OrderTrackingEvent($orderData, $orderIdentifier, $payload);
            $this->eventDispatcher->dispatch($trackingEvent, OrderTrackingEvent::NAME);
            $orderData = $trackingEvent->getOrderData();
        }

        if ($orderData === null) {
            return new JsonResponse(
                ['error' => 'Order not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse($orderData);
    }

    private function verifySignature(string $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
