<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Emporiqa\SyliusPlugin\Service\ActionStateStore;
use Emporiqa\SyliusPlugin\Service\CustomerInfo;
use Emporiqa\SyliusPlugin\Service\CustomerPrices;
use Emporiqa\SyliusPlugin\Service\OrderStatusLookup;
use Emporiqa\SyliusPlugin\Service\SignatureHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints for Emporiqa ready-made rules, under the actions base URL
 * (/emporiqa/api/) the merchant confirms in the Order status rule:
 *
 *   actions/order-status     read-only order lookup, signed both ways (scheme 2)
 *   actions/customer-prices  what a signed-in customer pays, read-only
 *   actions/customer-info    who the signed-in customer is and their newest
 *                            orders, read-only
 *   actions/verify           the endpoint challenge that proves this shop
 *                            holds the store's secret
 *
 * Refusals before the signature is known to be good (401, 503) are unsigned:
 * nothing proves the caller, so there is nothing to sign for. So is a 400
 * without a request_id, since the response signature covers it.
 */
class ActionController
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private string $webhookSecret,
        private string $storeId,
        private OrderStatusLookup $orderStatus,
        private ActionStateStore $state,
        private ?LoggerInterface $logger = null,
        private ?CustomerPrices $customerPrices = null,
        private ?CustomerInfo $customerInfo = null,
    ) {}

    public function verify(Request $request): Response
    {
        return $this->handle($request, 'verify');
    }

    public function orderStatus(Request $request): Response
    {
        return $this->handle($request, 'order_status');
    }

    public function customerPrices(Request $request): Response
    {
        return $this->handle($request, 'customer_prices');
    }

    public function customerInfo(Request $request): Response
    {
        return $this->handle($request, 'customer_info');
    }

    private function handle(Request $request, string $key): Response
    {
        if ($this->webhookSecret === '' || $this->storeId === '') {
            return $this->respond(503, ['status' => 'error', 'message_code' => 'disabled']);
        }

        $body = $request->getContent();
        $verdict = SignatureHelper::verifyHeader(
            (string) $request->headers->get('X-Emporiqa-Action-Signature', ''),
            $body,
            $this->webhookSecret,
            $this->storeId,
            SignatureHelper::LABEL_OUTBOUND,
        );
        if ($verdict !== 'ok') {
            return $this->respond(401, ['status' => 'error', 'message_code' => $verdict]);
        }

        $payload = json_decode($body, true);
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null) ? $payload['request_id'] : '';
        if ($requestId === '' || strlen($requestId) > 100 || !is_string($payload['rule'] ?? null)) {
            return $this->respond(400, ['status' => 'error', 'message_code' => 'invalid_field']);
        }

        if ($payload['rule'] !== $key ||
            ($key === 'customer_prices' && $this->customerPrices === null) ||
            ($key === 'customer_info' && $this->customerInfo === null)
        ) {
            return $this->respond(404, ['status' => 'error', 'message_code' => 'disabled'], $requestId);
        }
        if ($key === 'verify') {
            return $this->answerChallenge($payload, $requestId);
        }

        try {
            // Per rule and per exact body: an answer is only ever replayed on
            // the endpoint that gave it, for the same request (a reused
            // request_id with another customer or order is a new call).
            $replayKey = $key . ':' . $requestId . ':' . hash('sha256', $body);
            $remembered = $this->state->remembered($replayKey);
            if ($remembered !== null) {
                return $this->respond($remembered[0], $remembered[1], $requestId);
            }

            // Counted after the dedupe, so Emporiqa's retry of one call is free.
            $limited = match ($key) {
                'order_status' => $this->state->rateLimitHit(
                    OrderStatusLookup::field($payload, 'order_number'),
                    OrderStatusLookup::field($payload, 'email'),
                ),
                'customer_info' => $this->state->customerInfoLimitHit(CustomerPrices::customerId($payload)),
                default => $this->state->customerPriceLimitHit(CustomerPrices::customerId($payload)),
            };
            if ($limited !== null) {
                return $this->respond(
                    429,
                    ['status' => 'error', 'message_code' => 'rate_limited', 'data' => ['scope' => $limited['scope']]],
                    $requestId,
                    ['Retry-After' => (string) $limited['retry_after']],
                );
            }

            $answer = match ($key) {
                'order_status' => $this->orderStatus->handle($payload),
                'customer_info' => $this->customerInfo->handle($payload),
                default => $this->customerPrices->handle($payload),
            };
            $encoded = (string) json_encode($answer, self::JSON_FLAGS);
            $this->state->remember($replayKey, 200, $encoded);
        } catch (\Throwable $e) {
            // The class only: a database error's message can quote the email or order number.
            $this->logger?->error('Emporiqa ' . $payload['rule'] . ' failed', ['exception_class' => $e::class]);

            return $this->respond(500, ['status' => 'error', 'message_code' => 'internal'], $requestId);
        }

        return $this->respond(200, $encoded, $requestId);
    }

    /**
     * HMAC-SHA256(K_resp, challenge), signed as any answer. Emporiqa's
     * challenge is always 64 lowercase hex; anything else is refused, so a
     * caller cannot have the shop MAC a value of its choosing.
     */
    private function answerChallenge(array $payload, string $requestId): Response
    {
        $challenge = is_string($payload['challenge'] ?? null) ? $payload['challenge'] : '';
        if (!preg_match('/^[0-9a-f]{64}$/D', $challenge)) {
            return $this->respond(200, ['status' => 'rejected', 'message_code' => 'invalid_field'], $requestId);
        }
        $key = SignatureHelper::deriveKey($this->webhookSecret, SignatureHelper::LABEL_RESPONSE, $this->storeId);

        return $this->respond(200, [
            'status' => 'found',
            'data' => ['challenge_mac' => hash_hmac('sha256', $challenge, $key)],
        ], $requestId);
    }

    /**
     * @param array|string $envelope an array is encoded; a string is an already encoded body (a remembered answer)
     * @param string|null $requestId when set, the answer carries X-Emporiqa-Response-Signature over the exact bytes sent
     * @param array<string, string> $headers
     */
    private function respond(int $httpCode, array|string $envelope, ?string $requestId = null, array $headers = []): Response
    {
        $body = is_array($envelope) ? (string) json_encode($envelope, self::JSON_FLAGS) : $envelope;
        $headers['Content-Type'] = 'application/json';
        $headers['Cache-Control'] = 'no-store';
        if ($requestId !== null) {
            $key = SignatureHelper::deriveKey($this->webhookSecret, SignatureHelper::LABEL_RESPONSE, $this->storeId);
            $headers['X-Emporiqa-Response-Signature'] = SignatureHelper::buildHeader($key, $requestId . '.' . $body);
        }

        return new Response($body, $httpCode, $headers);
    }
}
