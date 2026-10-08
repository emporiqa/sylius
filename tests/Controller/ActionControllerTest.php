<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Controller\ActionController;
use Emporiqa\SyliusPlugin\Service\ActionStateStore;
use Emporiqa\SyliusPlugin\Service\CustomerInfo;
use Emporiqa\SyliusPlugin\Service\CustomerPrices;
use Emporiqa\SyliusPlugin\Service\OrderStatusLookup;
use Emporiqa\SyliusPlugin\Service\SignatureHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ready-made rule endpoints: our signature checked (current or previous
 * secret during a change), the envelope signed over the exact bytes sent,
 * one answer for "no order" and "not yours", replays answered the same,
 * and lookups rate limited.
 */
class ActionControllerTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';
    private const OLD_SECRET = 'old-secret-fedcba9876543210';
    private const STORE_ID = 'st_7Kq2mXa9';

    private OrderRepositoryInterface $orders;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->orders = $this->createMock(OrderRepositoryInterface::class);
        $this->cache = new ArrayAdapter();
    }

    private function controller(string $secret = self::SECRET, ?CustomerPrices $prices = null, ?AbstractLogger $logger = null, ?CustomerInfo $info = null): ActionController
    {
        return new ActionController(
            $secret,
            self::STORE_ID,
            new OrderStatusLookup($this->orders),
            new ActionStateStore($this->cache),
            $logger,
            $prices,
            $info,
        );
    }

    private function infoPayload(string $requestId = 'i-1', string $customerId = '77'): array
    {
        return ['rule' => 'customer_info', 'request_id' => $requestId, 'customer' => ['id' => $customerId]];
    }

    private function infoRequest(array $payload, array $secrets = [self::SECRET]): Request
    {
        return $this->request($payload, $secrets, null, '/emporiqa/api/actions/customer-info');
    }

    public function testCustomerInfoIsSignedBothWays(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->expects($this->once())->method('handle')->willReturn(['status' => 'found', 'data' => ['customer' => ['name' => 'Anna'], 'orders' => []]]);
        $controller = $this->controller(self::SECRET, null, null, $info);

        $unsigned = Request::create('/emporiqa/api/actions/customer-info', 'POST', [], [], [], [], (string) json_encode($this->infoPayload()));
        $this->assertSame(401, $controller->customerInfo($unsigned)->getStatusCode());
        $this->assertSame(401, $controller->customerInfo($this->infoRequest($this->infoPayload(), ['another-secret']))->getStatusCode());

        $response = $controller->customerInfo($this->infoRequest($this->infoPayload()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"found","data":{"customer":{"name":"Anna"},"orders":[]}}', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSignedResponse($response, 'i-1');
    }

    /** During a secret change Emporiqa signs with both; either must do. */
    public function testCustomerInfoAcceptsTheOldSecretDuringAChange(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->method('handle')->willReturn(['status' => 'not_found']);

        $response = $this->controller(self::SECRET, null, null, $info)->customerInfo($this->infoRequest($this->infoPayload(), ['the-new-secret', self::SECRET]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCustomerInfoRuleOnlyAnswersOnItsOwnEndpoint(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->expects($this->never())->method('handle');
        $controller = $this->controller(self::SECRET, null, null, $info);

        $this->assertSame(404, $controller->orderStatus($this->request($this->infoPayload()))->getStatusCode());
        $this->assertSame(404, $controller->customerInfo($this->infoRequest($this->pricesPayload()))->getStatusCode());
    }

    public function testCustomerInfoIsOffWithoutTheService(): void
    {
        $response = $this->controller()->customerInfo($this->infoRequest($this->infoPayload()));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('disabled', $this->json($response)['message_code']);
    }

    /** Emporiqa's retry of one request_id gets the same bytes, looked up once and not counted. */
    public function testCustomerInfoReplaysARequestId(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->expects($this->once())->method('handle')->willReturn(['status' => 'found', 'data' => ['orders' => []]]);
        $controller = $this->controller(self::SECRET, null, null, $info);

        $first = $controller->customerInfo($this->infoRequest($this->infoPayload('same')));
        $second = $controller->customerInfo($this->infoRequest($this->infoPayload('same')));

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSignedResponse($second, 'same');
    }

    /** A reused request_id with another body (another customer) is a new call, never another customer's answer. */
    public function testAReusedRequestIdWithAnotherBodyIsNotReplayed(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->expects($this->exactly(2))->method('handle')->willReturnCallback(
            fn (array $payload): array => ['status' => 'found', 'data' => ['customer' => ['name' => 'Customer ' . $payload['customer']['id']]]],
        );
        $controller = $this->controller(self::SECRET, null, null, $info);

        $first = $controller->customerInfo($this->infoRequest($this->infoPayload('reused', '77')));
        $second = $controller->customerInfo($this->infoRequest($this->infoPayload('reused', '78')));

        $this->assertSame('Customer 77', $this->json($first)['data']['customer']['name']);
        $this->assertSame('Customer 78', $this->json($second)['data']['customer']['name']);
    }

    public function testCustomerInfoIsRateLimitedPerCustomer(): void
    {
        $info = $this->createMock(CustomerInfo::class);
        $info->method('handle')->willReturn(['status' => 'found', 'data' => []]);
        $controller = $this->controller(self::SECRET, null, null, $info);

        for ($i = 0; $i < ActionStateStore::INFO_RATE_PER_CUSTOMER; ++$i) {
            $this->assertSame(200, $controller->customerInfo($this->infoRequest($this->infoPayload('i' . $i)))->getStatusCode());
        }
        $limited = $controller->customerInfo($this->infoRequest($this->infoPayload('i-over')));
        $other = $controller->customerInfo($this->infoRequest($this->infoPayload('i-other', '78')));

        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame(['scope' => 'value'], $this->json($limited)['data']);
        $this->assertNotEmpty($limited->headers->get('Retry-After'));
        $this->assertSignedResponse($limited, 'i-over');
        $this->assertSame(200, $other->getStatusCode(), 'another customer has their own bucket');
    }

    public function testCustomerInfoHasItsOwnStoreCeiling(): void
    {
        $state = new ActionStateStore($this->cache);
        $now = time();
        for ($i = 0; $i < ActionStateStore::INFO_RATE_PER_STORE; ++$i) {
            $state->customerInfoLimitHit('c' . $i, $now);
        }

        $this->assertSame('store', $state->customerInfoLimitHit('fresh', $now)['scope']);
        $this->assertNull($state->customerPriceLimitHit('fresh', $now), 'customer_prices has its own store ceiling');
        $this->assertNull($state->rateLimitHit('42', 'a@example.com', $now), 'order_status has its own store ceiling');
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $message . ' ' . json_encode($context);
            }
        };
    }

    private function pricesPayload(string $requestId = 'p-1', string $customerId = '77'): array
    {
        return [
            'rule' => 'customer_prices',
            'request_id' => $requestId,
            'currency' => 'USD',
            'channel' => 'default',
            'customer' => ['id' => $customerId],
            'products' => ['product-1'],
        ];
    }

    /**
     * @param string[] $secrets one v1 per secret, newest first, like Emporiqa during a change
     */
    private function request(array $payload, array $secrets = [self::SECRET], ?int $t = null, string $path = '/emporiqa/api/actions/order-status'): Request
    {
        $body = (string) json_encode($payload);
        $t ??= time();
        $header = 't=' . $t;
        foreach ($secrets as $secret) {
            $key = SignatureHelper::deriveKey($secret, SignatureHelper::LABEL_OUTBOUND, self::STORE_ID);
            $header .= ',v1=' . hash_hmac('sha256', $t . '.' . $body, $key);
        }
        $request = Request::create($path, 'POST', [], [], [], [], $body);
        $request->headers->set('X-Emporiqa-Action-Signature', $header);

        return $request;
    }

    private function orderStatusPayload(array $fields, ?array $customer = null, string $requestId = 'req-1'): array
    {
        return [
            'rule' => 'order_status',
            'request_id' => $requestId,
            'signed_in' => $customer !== null,
            'test' => false,
            'fields' => $fields,
            'customer' => $customer,
            'context' => [],
        ];
    }

    private function order(string $email = 'anna@example.com', int $customerId = 77): OrderInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getId')->willReturn($customerId);

        $method = $this->createMock(ShippingMethodInterface::class);
        $method->method('getName')->willReturn('DHL Express');
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getTracking')->willReturn('JD0123');
        $shipment->method('getMethod')->willReturn($method);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCheckoutCompletedAt')->willReturn(new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $order->method('getState')->willReturn('new');
        $order->method('getPaymentState')->willReturn('paid');
        $order->method('getShippingState')->willReturn('shipped');
        $order->method('getShipments')->willReturn(new ArrayCollection([$shipment]));

        return $order;
    }

    private function assertSignedResponse(Response $response, string $requestId, string $secret = self::SECRET): void
    {
        $header = (string) $response->headers->get('X-Emporiqa-Response-Signature');
        $this->assertSame('ok', SignatureHelper::verifyHeader(
            $header,
            $requestId . '.' . $response->getContent(),
            $secret,
            self::STORE_ID,
            SignatureHelper::LABEL_RESPONSE,
        ), 'response signature');
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function testFoundOrderAnswersTheSchemaAndSignsTheAnswer(): void
    {
        $this->orders->method('findOneByNumber')->willReturnMap([
            ['000000042', $this->order()],
        ]);

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '#42', 'email' => 'ANNA@example.com']),
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSignedResponse($response, 'req-1');
        $this->assertSame([
            'status' => 'found',
            'data' => [
                'status_code' => 'shipped',
                'placed_at' => '2026-10-01T10:00:00+00:00',
                'tracking' => [['carrier' => 'DHL Express', 'number' => 'JD0123']],
                'totals' => ['subtotal' => 0, 'shipping' => 0, 'total' => 0],
                'payment_status' => 'paid',
                'shipping_method' => 'DHL Express',
            ],
        ], $this->json($response));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testWrongEmailAndUnknownOrderGiveTheSameAnswer(): void
    {
        $this->orders->method('findOneByNumber')->willReturnCallback(
            fn (string $number) => $number === '000000042' ? $this->order() : null,
        );

        $wrongEmail = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'eve@example.com'], null, 'a'),
        ));
        $unknown = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '43', 'email' => 'anna@example.com'], null, 'b'),
        ));

        $this->assertSame(200, $wrongEmail->getStatusCode());
        $this->assertSame(['status' => 'not_found'], $this->json($wrongEmail));
        $this->assertSame($wrongEmail->getContent(), $unknown->getContent());
    }

    public function testSignedInCustomerFindsTheirOwnOrderWithoutEmail(): void
    {
        $this->orders->method('findOneByNumber')->willReturn($this->order('anna@example.com', 77));

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '000000042'], ['id' => '77']),
        ));

        $this->assertSame('found', $this->json($response)['status']);
    }

    public function testAnotherCustomersOrderIsNotFound(): void
    {
        // Customer B's id with customer A's order.
        $this->orders->method('findOneByNumber')->willReturn($this->order('anna@example.com', 77));

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '000000042'], ['id' => '78']),
        ));

        $this->assertSame(['status' => 'not_found'], $this->json($response));
    }

    public function testSignedInShopperFindsAGuestOrderByItsEmail(): void
    {
        $this->orders->method('findOneByNumber')->willReturn($this->order('guest@example.com', 12));

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '000000042', 'email' => 'guest@example.com'], ['id' => '77']),
        ));

        $this->assertSame('found', $this->json($response)['status']);
    }

    public function testACartIsNeverAnOrder(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getCheckoutCompletedAt')->willReturn(null);
        $this->orders->method('findOneByNumber')->willReturn($cart);

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '000000042', 'email' => 'anna@example.com']),
        ));

        $this->assertSame(['status' => 'not_found'], $this->json($response));
    }

    public function testMissingFieldsAreRejectedBeforeAnyLookup(): void
    {
        $this->orders->expects($this->never())->method('findOneByNumber');

        $response = $this->controller()->orderStatus($this->request($this->orderStatusPayload([])));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'rejected', 'ask' => ['order_number', 'email'], 'message_code' => 'missing_field'],
            $this->json($response),
        );
    }

    public function testMalformedFieldsAreRejectedBeforeAnyLookup(): void
    {
        $this->orders->expects($this->never())->method('findOneByNumber');

        foreach ([
            ['order_number' => '42; DROP', 'email' => 'anna@example.com'],
            ['order_number' => str_repeat('1', 65), 'email' => 'anna@example.com'],
            ['order_number' => '42', 'email' => 'not-an-email'],
        ] as $i => $fields) {
            $response = $this->controller()->orderStatus($this->request($this->orderStatusPayload($fields, null, 'r' . $i)));
            $this->assertSame(['status' => 'rejected', 'message_code' => 'invalid_field'], $this->json($response));
        }
    }

    public function testInvalidSignatureIsAnUnsigned401(): void
    {
        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'anna@example.com']),
            ['wrong-secret'],
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"status":"error","message_code":"signature"}', $response->getContent());
        $this->assertFalse($response->headers->has('X-Emporiqa-Response-Signature'));
    }

    public function testMissingSignatureIsRefused(): void
    {
        $request = Request::create('/emporiqa/api/actions/order-status', 'POST', [], [], [], [], '{"rule":"order_status"}');

        $response = $this->controller()->orderStatus($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('signature', $this->json($response)['message_code']);
    }

    public function testExpiredSignatureIsAnUnsigned401(): void
    {
        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'anna@example.com']),
            [self::SECRET],
            time() - 301,
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"status":"error","message_code":"expired"}', $response->getContent());
    }

    public function testSignedWithBothSecretsDuringAChangeVerifiesWithEither(): void
    {
        $this->orders->method('findOneByNumber')->willReturn(null);
        $payload = $this->orderStatusPayload(['order_number' => '42', 'email' => 'anna@example.com']);

        // The shop still holds the old secret: the second v1 matches.
        $old = $this->controller(self::OLD_SECRET)->orderStatus($this->request($payload, [self::SECRET, self::OLD_SECRET]));
        $this->assertSame(200, $old->getStatusCode());
        $this->assertSignedResponse($old, 'req-1', self::OLD_SECRET);

        $this->cache->clear();
        $new = $this->controller()->orderStatus($this->request($payload, [self::SECRET, self::OLD_SECRET]));
        $this->assertSame(200, $new->getStatusCode());
    }

    public function testVerifyAnswersTheChallengeUnderTheResponseKey(): void
    {
        $challenge = str_repeat('ab', 32);
        $request = $this->request(
            ['rule' => 'verify', 'challenge' => $challenge, 'request_id' => 'v-1', 'test' => true],
            [self::SECRET],
            null,
            '/emporiqa/api/actions/verify',
        );

        $response = $this->controller()->verify($request);

        $key = SignatureHelper::deriveKey(self::SECRET, SignatureHelper::LABEL_RESPONSE, self::STORE_ID);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'found', 'data' => ['challenge_mac' => hash_hmac('sha256', $challenge, $key)]],
            $this->json($response),
        );
        $this->assertSignedResponse($response, 'v-1');
    }

    public function testVerifyRefusesAChallengeOfTheCallersChoosing(): void
    {
        $request = $this->request(
            ['rule' => 'verify', 'challenge' => 'please-mac-this', 'request_id' => 'v-1'],
            [self::SECRET],
            null,
            '/emporiqa/api/actions/verify',
        );

        $response = $this->controller()->verify($request);

        $this->assertSame(['status' => 'rejected', 'message_code' => 'invalid_field'], $this->json($response));
    }

    public function testVerifyIsRefusedWithoutAValidSignature(): void
    {
        $request = $this->request(
            ['rule' => 'verify', 'challenge' => str_repeat('ab', 32), 'request_id' => 'v-1'],
            ['wrong-secret'],
            null,
            '/emporiqa/api/actions/verify',
        );

        $this->assertSame(401, $this->controller()->verify($request)->getStatusCode());
    }

    public function testRuleMustMatchTheEndpoint(): void
    {
        $request = $this->request(['rule' => 'verify', 'challenge' => str_repeat('ab', 32), 'request_id' => 'x']);

        $response = $this->controller()->orderStatus($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSignedResponse($response, 'x');
    }

    public function testMissingRequestIdIs400(): void
    {
        $response = $this->controller()->orderStatus($this->request(['rule' => 'order_status']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_field', $this->json($response)['message_code']);
    }

    public function testReplayedRequestIdGetsTheSameAnswerWithoutASecondLookup(): void
    {
        $this->orders->expects($this->once())->method('findOneByNumber')->willReturn($this->order());
        $payload = $this->orderStatusPayload(['order_number' => '000000042', 'email' => 'anna@example.com']);

        $first = $this->controller()->orderStatus($this->request($payload));
        $second = $this->controller()->orderStatus($this->request($payload));

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSignedResponse($second, 'req-1');
    }

    public function testLookupsOfOneOrderNumberAreRateLimited(): void
    {
        $this->orders->method('findOneByNumber')->willReturn(null);

        for ($i = 1; $i <= ActionStateStore::RATE_PER_VALUE; ++$i) {
            $response = $this->controller()->orderStatus($this->request(
                $this->orderStatusPayload(['order_number' => '42', 'email' => 'e' . $i . '@example.com'], null, 'r' . $i),
            ));
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'last@example.com'], null, 'r-last'),
        ));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'message_code' => 'rate_limited', 'data' => ['scope' => 'value']],
            $this->json($response),
        );
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSignedResponse($response, 'r-last');
    }

    public function testInternalErrorIsAGeneric500(): void
    {
        $this->orders->method('findOneByNumber')->willThrowException(new \RuntimeException('SQLSTATE secret detail'));

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'anna@example.com']),
        ));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('{"status":"error","message_code":"internal"}', $response->getContent());
        $this->assertSignedResponse($response, 'req-1');
    }

    public function testWithoutASecretTheEndpointIsDisabled(): void
    {
        $response = $this->controller('')->orderStatus($this->request(['rule' => 'order_status', 'request_id' => 'x']));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('{"status":"error","message_code":"disabled"}', $response->getContent());
    }

    public function testMissingRequestIdIs400WithoutASignatureItCouldCover(): void
    {
        $response = $this->controller()->orderStatus($this->request(['rule' => 'order_status']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-Emporiqa-Response-Signature'));
    }

    public function testSpellingsOfOneOrderNumberShareARateLimit(): void
    {
        $this->orders->method('findOneByNumber')->willReturn(null);
        $spellings = ['42', '#42', '042', '000000042', ' 42'];

        for ($i = 0; $i < ActionStateStore::RATE_PER_VALUE; ++$i) {
            $response = $this->controller()->orderStatus($this->request($this->orderStatusPayload(
                ['order_number' => $spellings[$i % count($spellings)], 'email' => 'e' . $i . '@example.com'],
                null,
                'n' . $i,
            )));
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->controller()->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '#0042', 'email' => 'last@example.com'], null, 'n-last'),
        ));

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testAFailedLookupIsLoggedWithoutTheShoppersValues(): void
    {
        $this->orders->method('findOneByNumber')->willThrowException(
            new \RuntimeException("SQLSTATE: WHERE email = 'anna@example.com' AND number = '000000042'"),
        );
        $logger = $this->logger();

        $this->controller(self::SECRET, null, $logger)->orderStatus($this->request(
            $this->orderStatusPayload(['order_number' => '42', 'email' => 'anna@example.com']),
        ));

        $this->assertCount(1, $logger->records);
        $this->assertStringNotContainsString('anna@example.com', $logger->records[0]);
        $this->assertStringNotContainsString('000000042', $logger->records[0]);
        $this->assertStringContainsString('RuntimeException', $logger->records[0]);
    }

    public function testCustomerPricesIsSignedBothWays(): void
    {
        $prices = $this->createMock(CustomerPrices::class);
        $prices->expects($this->once())->method('handle')->willReturn(['status' => 'not_found']);
        $controller = $this->controller(self::SECRET, $prices);

        $unsigned = Request::create('/emporiqa/api/actions/customer-prices', 'POST', [], [], [], [], (string) json_encode($this->pricesPayload()));
        $this->assertSame(401, $controller->customerPrices($unsigned)->getStatusCode());

        $response = $controller->customerPrices($this->request($this->pricesPayload(), [self::SECRET], null, '/emporiqa/api/actions/customer-prices'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"not_found"}', $response->getContent());
        $this->assertSignedResponse($response, 'p-1');
    }

    public function testCustomerPricesRuleOnlyAnswersOnItsOwnEndpoint(): void
    {
        $prices = $this->createMock(CustomerPrices::class);
        $prices->expects($this->never())->method('handle');

        $response = $this->controller(self::SECRET, $prices)->orderStatus($this->request($this->pricesPayload()));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCustomerPricesAreRateLimitedPerCustomer(): void
    {
        $prices = $this->createMock(CustomerPrices::class);
        $prices->method('handle')->willReturn(['status' => 'found', 'data' => []]);
        $controller = $this->controller(self::SECRET, $prices);

        for ($i = 0; $i < ActionStateStore::PRICE_RATE_PER_CUSTOMER; ++$i) {
            $this->assertSame(200, $controller->customerPrices($this->request($this->pricesPayload('p' . $i)))->getStatusCode());
        }

        $limited = $controller->customerPrices($this->request($this->pricesPayload('p-over')));
        $other = $controller->customerPrices($this->request($this->pricesPayload('p-other', '78')));

        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame(['scope' => 'value'], $this->json($limited)['data']);
        $this->assertSignedResponse($limited, 'p-over');
        $this->assertSame(200, $other->getStatusCode(), 'another customer has their own bucket');
    }

    public function testCustomerPricesHaveTheirOwnStoreCeiling(): void
    {
        $state = new ActionStateStore($this->cache);
        $now = time();
        for ($i = 0; $i < ActionStateStore::PRICE_RATE_PER_STORE; ++$i) {
            $state->customerPriceLimitHit('c' . $i, $now);
        }

        $this->assertSame('store', $state->customerPriceLimitHit('fresh', $now)['scope']);
        $this->assertNull($state->rateLimitHit('42', 'a@example.com', $now), 'order_status has its own store ceiling');
    }

    public function testARememberedAnswerIsOnlyReplayedOnItsOwnEndpoint(): void
    {
        $this->orders->method('findOneByNumber')->willReturn(null);
        $prices = $this->createMock(CustomerPrices::class);
        $prices->method('handle')->willReturn(['status' => 'found', 'data' => ['currency' => 'USD']]);
        $controller = $this->controller(self::SECRET, $prices);

        $first = $controller->orderStatus($this->request($this->orderStatusPayload(['order_number' => '42', 'email' => 'a@example.com'], null, 'same-id')));
        $second = $controller->customerPrices($this->request($this->pricesPayload('same-id')));

        $this->assertSame('not_found', $this->json($first)['status']);
        $this->assertSame('found', $this->json($second)['status']);
    }

    /**
     * A call refused for its own value is not counted against the store, so
     * one shopper repeating one order number cannot lock everyone else out.
     */
    public function testAValueOverItsLimitDoesNotUseUpTheStoreCeiling(): void
    {
        $state = new ActionStateStore($this->cache);
        $now = time();
        for ($i = 0; $i < ActionStateStore::RATE_PER_STORE + 5; ++$i) {
            $hit = $state->rateLimitHit('42', '', $now);
            if ($i < ActionStateStore::RATE_PER_VALUE) {
                $this->assertNull($hit);
            } else {
                $this->assertSame('value', $hit['scope']);
            }
        }

        for ($i = 0; $i < ActionStateStore::RATE_PER_STORE - ActionStateStore::RATE_PER_VALUE; ++$i) {
            $this->assertNull($state->rateLimitHit('n' . $i, '', $now), 'call ' . $i);
        }
        $this->assertSame('store', $state->rateLimitHit('fresh', '', $now)['scope']);
    }

    public function testACounterThatCannotBeStoredFailsClosed(): void
    {
        $pool = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(fn (string $key) => (new ArrayAdapter())->getItem($key));
        $pool->method('save')->willReturn(false);
        $this->assertSame('store', (new ActionStateStore($pool))->rateLimitHit('42', 'a@example.com')['scope']);

        $broken = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $broken->method('getItem')->willThrowException(new \RuntimeException('cache down'));
        $this->assertSame('store', (new ActionStateStore($broken))->customerPriceLimitHit('77')['scope']);
    }

    public function testCustomerPricesWithoutTheServiceIsDisabled(): void
    {
        $response = $this->controller()->customerPrices($this->request($this->pricesPayload()));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('disabled', $this->json($response)['message_code']);
    }
}
