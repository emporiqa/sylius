<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Short-lived state of the ready-made rule calls, kept in the app cache pool
 * (no tables to migrate): the answer given per request_id, and the rate-limit
 * counters.
 *
 * The counters are only as shared as `cache.app`: the filesystem default is
 * shared by every PHP worker on one host, Redis or Memcached by every host.
 * APCu or an array pool would give each worker its own counts. An increment
 * is a read then a write, so two lookups in the same instant can count once.
 */
class ActionStateStore
{
    /** Emporiqa retries a call with the same request_id for up to 10 minutes. */
    public const DEDUPE_TTL_SECONDS = 600;

    /**
     * A validly signed caller still must not walk order numbers or emails.
     * 10 lookups per value per 10-minute window is far above what one shopper
     * asking about one order needs; the store ceiling caps the total when the
     * values are varied instead.
     */
    public const RATE_WINDOW_SECONDS = 600;

    public const RATE_PER_VALUE = 10;

    public const RATE_PER_STORE = 300;

    /** customer_prices: per signed-in customer and per store, per window. */
    public const PRICE_RATE_PER_CUSTOMER = 30;

    public const PRICE_RATE_PER_STORE = 600;

    private const STORE_BUCKETS = ['store', 'customer_prices:store'];

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {}

    /**
     * @return array{0: int, 1: string}|null HTTP code and raw body
     */
    public function remembered(string $requestId): ?array
    {
        try {
            $item = $this->cache->getItem($this->key('req', $requestId));
            $value = $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) && isset($value[0], $value[1]) ? [(int) $value[0], (string) $value[1]] : null;
    }

    public function remember(string $requestId, int $httpCode, string $body): void
    {
        try {
            $item = $this->cache->getItem($this->key('req', $requestId));
            $item->set([$httpCode, $body]);
            $item->expiresAfter(self::DEDUPE_TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable) {
            // A lost entry only means a retry is looked up again.
        }
    }

    /**
     * order_status: count this lookup against its order number (normalised,
     * so "#42" and "042" are one bucket), its email and the store.
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public function rateLimitHit(string $orderNumber, string $email, ?int $now = null): ?array
    {
        $buckets = ['store' => self::RATE_PER_STORE];
        $orderNumber = OrderStatusLookup::normalizeOrderNumber($orderNumber);
        if ($orderNumber !== '') {
            $buckets['order_number:' . $orderNumber] = self::RATE_PER_VALUE;
        }
        $email = mb_strtolower(trim($email));
        if ($email !== '') {
            $buckets['email:' . $email] = self::RATE_PER_VALUE;
        }

        return $this->count($buckets, $now);
    }

    /**
     * customer_prices: count this call against the customer and the store.
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public function customerPriceLimitHit(string $customerId, ?int $now = null): ?array
    {
        return $this->count([
            'customer_prices:store' => self::PRICE_RATE_PER_STORE,
            'customer_prices:customer:' . $customerId => self::PRICE_RATE_PER_CUSTOMER,
        ], $now);
    }

    /**
     * Say which limit this call is over, and count it only when it is under
     * all of them: null under every limit, scope "value" when one of its own
     * values (order number, email, customer) is used up, "store" when the
     * store ceiling is, with the seconds until the window ends. A refused
     * call is not counted, so one shopper hammering one value cannot use up
     * the store ceiling everyone else shares.
     *
     * Fails closed: a counter that cannot be read or written counts as the
     * store ceiling, since an uncounted lookup is what the limit exists to stop.
     *
     * @param array<string, int> $buckets bucket name => limit
     *
     * @return array{scope: string, retry_after: int}|null
     */
    private function count(array $buckets, ?int $now): ?array
    {
        $now ??= time();
        $windowEnd = $now - ($now % self::RATE_WINDOW_SECONDS) + self::RATE_WINDOW_SECONDS;
        $limited = fn (string $scope): array => ['scope' => $scope, 'retry_after' => max(1, $windowEnd - $now)];

        $items = [];
        $valueSpent = false;
        $storeSpent = false;
        try {
            foreach ($buckets as $bucket => $limit) {
                $item = $this->cache->getItem($this->key('rate', $bucket . '|' . $windowEnd));
                $hits = $item->isHit() ? (int) $item->get() : 0;
                if ($hits >= $limit && in_array($bucket, self::STORE_BUCKETS, true)) {
                    $storeSpent = true;
                } elseif ($hits >= $limit) {
                    $valueSpent = true;
                }
                $items[] = [$item, $hits];
            }
        } catch (\Throwable) {
            return $limited('store');
        }
        // A used-up value is named first: the store is not out, that value is.
        if ($valueSpent || $storeSpent) {
            return $limited($valueSpent ? 'value' : 'store');
        }

        try {
            foreach ($items as [$item, $hits]) {
                $item->set($hits + 1);
                $item->expiresAfter(max(1, $windowEnd - $now));
                if (!$this->cache->save($item)) {
                    return $limited('store');
                }
            }
        } catch (\Throwable) {
            return $limited('store');
        }

        return null;
    }

    /**
     * Hashed: PSR-6 keys may not hold "{}()/\@:", and the values are shopper input.
     */
    private function key(string $prefix, string $value): string
    {
        return 'emporiqa_action_' . $prefix . '_' . hash('sha256', $value);
    }
}
