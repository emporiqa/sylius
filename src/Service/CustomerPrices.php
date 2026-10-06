<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Addressing\Matcher\ZoneMatcherInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Factory\AddressFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\Scope;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The `customer_prices` ready-made rule: what one signed-in customer pays
 * for up to 20 synced products in one channel, read-only.
 *
 * Sylius core prices by channel only (channel pricing with its catalog
 * promotions); it has no customer-group or per-customer prices, and cart
 * promotions are not catalog prices. So for a core shop the answer equals
 * the synced price of that channel. The calculation still runs as the
 * customer, with their shop user in the token storage, so an extension that
 * decorates Sylius's price calculator and reads the customer context answers
 * for them; the previous token is always put back.
 *
 * Every product and variation entry says whether its own prices include
 * tax (`prices_include_tax`); the top-level flag is true only when all do.
 *
 * A product or variant the customer cannot buy in that channel (disabled,
 * not in the channel, no price there, unknown id) is left out, never an
 * error. An unknown customer, or one without an enabled shop account, is
 * `not_found`. Nothing about the customer is returned, only prices.
 */
class CustomerPrices
{
    public const MAX_PRODUCTS = 20;

    public function __construct(
        private CustomerRepositoryInterface $customers,
        private ProductRepositoryInterface $products,
        private ProductVariantRepositoryInterface $variants,
        private ChannelMappingResolver $channels,
        private PriceEntryBuilder $priceEntries,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?ZoneMatcherInterface $zoneMatcher = null,
        private ?AddressFactoryInterface $addressFactory = null,
    ) {}

    /**
     * The verified customer id, '' when missing or not an id.
     */
    public static function customerId(array $payload): string
    {
        $id = $payload['customer']['id'] ?? null;

        return is_scalar($id) && ctype_digit((string) $id) ? (string) $id : '';
    }

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload): array
    {
        $customerId = self::customerId($payload);
        $ids = $payload['products'] ?? null;
        if ($customerId === '' || !is_array($ids) || $ids === []) {
            return ['status' => 'rejected', 'message_code' => 'missing_field'];
        }
        if (!array_is_list($ids) || count($ids) > self::MAX_PRODUCTS || array_filter($ids, 'is_string') !== $ids) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }
        $channel = $this->channels->findChannel(self::text($payload, 'channel'));
        if (!$channel instanceof ChannelInterface) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }

        $customer = $this->customers->find((int) $customerId);
        $user = $customer instanceof CustomerInterface ? $customer->getUser() : null;
        if (!$customer instanceof CustomerInterface || !$user instanceof ShopUserInterface || !$user->isEnabled()) {
            return ['status' => 'not_found'];
        }

        $currency = $this->priceEntries->quoteCurrency($channel, self::text($payload, 'currency'));
        $zone = $this->zone(self::text($payload, 'country')) ?? $channel->getDefaultTaxZone();

        $previous = $this->tokenStorage?->getToken();
        try {
            $this->tokenStorage?->setToken(new PostAuthenticationToken($user, 'shop', $user->getRoles()));
            [$products, $includesTax] = $this->quote(array_unique($ids), $channel, $currency, $zone, $customer);
        } finally {
            $this->tokenStorage?->setToken($previous);
        }

        return ['status' => 'found', 'data' => [
            'currency' => $currency,
            'prices_include_tax' => $includesTax,
            'products' => $products === [] ? new \stdClass() : $products,
        ]];
    }

    /**
     * @param string[] $ids
     *
     * @return array{0: array<string, array>, 1: bool}
     */
    private function quote(array $ids, ChannelInterface $channel, string $currency, ?ZoneInterface $zone, CustomerInterface $customer): array
    {
        $context = ['customer' => $customer];
        $products = [];
        $includesTax = true;

        foreach ($ids as $id) {
            if (preg_match('/^(product|variation)-(\d{1,18})$/D', $id, $m) !== 1) {
                continue;
            }

            if ($m[1] === 'variation') {
                $variant = $this->variants->find((int) $m[2]);
                if (!$variant instanceof ProductVariantInterface || !$this->buyable($variant->getProduct(), $channel)) {
                    continue;
                }
                $priced = $this->price($variant, $channel, $currency, $zone, $context);
                if ($priced !== null) {
                    $products[$id] = $priced;
                    $includesTax = $includesTax && $priced['prices_include_tax'];
                }

                continue;
            }

            $product = $this->products->find((int) $m[2]);
            if (!$product instanceof ProductInterface || !$this->buyable($product, $channel)) {
                continue;
            }
            $variations = [];
            foreach ($product->getEnabledVariants() as $variant) {
                if (!$variant instanceof ProductVariantInterface) {
                    continue;
                }
                $priced = $this->price($variant, $channel, $currency, $zone, $context);
                if ($priced !== null) {
                    $variations['variation-' . $variant->getId()] = $priced;
                    $includesTax = $includesTax && $priced['prices_include_tax'];
                }
            }
            if ($variations === []) {
                continue;
            }
            // The price the product page shows first: its first enabled variant's.
            $products[$id] = reset($variations);
            if ($product->getVariants()->count() > 1) {
                $products[$id]['variations'] = $variations;
            }
        }

        return [$products, $includesTax];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function price(ProductVariantInterface $variant, ChannelInterface $channel, string $currency, ?ZoneInterface $zone, array $context): ?array
    {
        if (!$variant->isEnabled()) {
            return null;
        }
        $entry = $this->priceEntries->entry($channel, $variant, $currency, $zone, $context);
        if ($entry === null) {
            return null;
        }
        unset($entry['currency']);
        $entry['prices_include_tax'] = $this->priceEntries->includesTax($variant, $zone);

        return $entry;
    }

    private function buyable(mixed $product, ChannelInterface $channel): bool
    {
        return $product instanceof ProductInterface && $product->isEnabled() && $product->hasChannel($channel);
    }

    /**
     * The tax zone of a country, as Sylius would match a billing address there.
     */
    private function zone(string $country): ?ZoneInterface
    {
        $country = strtoupper($country);
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1 || $this->zoneMatcher === null || $this->addressFactory === null) {
            return null;
        }
        $address = $this->addressFactory->createNew();
        $address->setCountryCode($country);

        return $this->zoneMatcher->match($address, Scope::TAX);
    }

    private static function text(array $payload, string $name): string
    {
        $value = $payload[$name] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
