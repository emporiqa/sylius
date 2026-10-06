<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Converter\CurrencyConverterInterface;
use Sylius\Component\Currency\Repository\ExchangeRateRepositoryInterface;
use Sylius\Component\Taxation\Calculator\CalculatorInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;

/**
 * Price entries (the ProductPrice schema) for one variant in one channel,
 * computed by Sylius's own services the way the storefront and cart get them:
 *
 * - the price and original price from the variant price calculator, so
 *   catalog promotions (already applied to the channel pricing) and any
 *   extension decorating the calculator are included;
 * - other currencies of the channel converted with the shop's exchange rate,
 *   as the storefront displays them (the cart itself is charged in the base
 *   currency). A currency without an exchange rate is left out: Sylius would
 *   show the base amount under that currency's symbol;
 * - incl/excl tax only when the tax zone is known, from the tax rate Sylius
 *   resolves for the variant in that zone. Without a zone Sylius charges no
 *   tax until the shopper's address decides one, so nothing is claimed.
 *
 * Every service is optional: without them the stored channel pricing is used.
 */
class PriceEntryBuilder
{
    public function __construct(
        private ?ProductVariantPricesCalculatorInterface $priceCalculator = null,
        private ?CurrencyConverterInterface $currencyConverter = null,
        private ?ExchangeRateRepositoryInterface $exchangeRates = null,
        private ?TaxRateResolverInterface $taxRateResolver = null,
        private ?CalculatorInterface $taxCalculator = null,
    ) {}

    /**
     * One entry for the channel's base currency, then one per other channel
     * currency the shop has an exchange rate for.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(ChannelInterface $channel, ?ProductVariantInterface $variant): array
    {
        if ($variant === null) {
            return [];
        }
        $base = $channel->getBaseCurrency()?->getCode() ?? '';
        $zone = $channel->getDefaultTaxZone();
        $entry = $this->entry($channel, $variant, $base, $zone);
        if ($entry === null) {
            return [];
        }

        $entries = [$entry];
        foreach ($channel->getCurrencies() as $currency) {
            $code = (string) $currency->getCode();
            if ($code !== $base && $this->canConvert($base, $code)) {
                $entries[] = $this->entry($channel, $variant, $code, $zone);
            }
        }

        return $entries;
    }

    /**
     * The currency a quote can be given in: the requested one when the
     * channel offers it and it can be converted, else the base currency.
     */
    public function quoteCurrency(ChannelInterface $channel, string $requested): string
    {
        $base = $channel->getBaseCurrency()?->getCode() ?? '';
        $requested = strtoupper($requested);
        if ($requested === '' || $requested === $base || !$this->canConvert($base, $requested)) {
            return $base;
        }
        foreach ($channel->getCurrencies() as $currency) {
            if ($currency->getCode() === $requested) {
                return $requested;
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $context extra calculator context (Sylius core reads only `channel`)
     *
     * @return array<string, mixed>|null null when the variant has no price in this channel
     */
    public function entry(
        ChannelInterface $channel,
        ProductVariantInterface $variant,
        string $currencyCode,
        ?ZoneInterface $zone,
        array $context = [],
    ): ?array {
        $context = ['channel' => $channel] + $context;
        $prices = $this->amounts($variant, $context);
        if ($prices === null) {
            return null;
        }
        [$price, $original] = $prices;

        $base = $channel->getBaseCurrency()?->getCode() ?? '';
        $units = fn (int $amount): float => CurrencyHelper::toCurrencyUnits(
            $currencyCode === $base || $this->currencyConverter === null
                ? $amount
                : $this->currencyConverter->convert($amount, $base, $currencyCode),
            $currencyCode,
        );

        $entry = [
            'currency' => $currencyCode,
            'current_price' => $units($price),
            'regular_price' => $units($original),
        ];

        $tax = $this->tax($variant, $zone, $price);
        if ($tax !== null) {
            $entry['price_incl_tax'] = $units($tax[0]);
            $entry['price_excl_tax'] = $units($tax[1]);
        }

        return $entry;
    }

    /**
     * Whether the price Sylius shows already contains the tax of this zone.
     * True when no rate applies: there is no tax to add.
     */
    public function includesTax(ProductVariantInterface $variant, ?ZoneInterface $zone): bool
    {
        $rate = $zone !== null ? $this->taxRateResolver?->resolve($variant, ['zone' => $zone]) : null;

        return $rate === null || $rate->isIncludedInPrice();
    }

    /**
     * @return array{0: int, 1: int}|null price and original price in the base currency's hundredths
     */
    private function amounts(ProductVariantInterface $variant, array $context): ?array
    {
        $channel = $context['channel'];
        $pricing = $variant->getChannelPricingForChannel($channel);
        if ($pricing === null || $pricing->getPrice() === null) {
            return null;
        }
        if ($this->priceCalculator === null) {
            return [$pricing->getPrice(), $pricing->getOriginalPrice() ?? $pricing->getPrice()];
        }

        return [
            $this->priceCalculator->calculate($variant, $context),
            $this->priceCalculator->calculateOriginal($variant, $context),
        ];
    }

    /**
     * Tax as Sylius's cart applies it to one unit: the resolved rate's
     * calculator, rounded to the hundredth like its tax adjustments.
     *
     * @return array{0: int, 1: int}|null incl and excl tax, null when the zone is unknown
     */
    private function tax(ProductVariantInterface $variant, ?ZoneInterface $zone, int $price): ?array
    {
        if ($zone === null || $this->taxRateResolver === null || $this->taxCalculator === null) {
            return null;
        }
        $rate = $this->taxRateResolver->resolve($variant, ['zone' => $zone]);
        if ($rate === null) {
            return [$price, $price];
        }
        $tax = (int) round($this->taxCalculator->calculate($price, $rate));

        return $rate->isIncludedInPrice() ? [$price, $price - $tax] : [$price + $tax, $price];
    }

    private function canConvert(string $base, string $target): bool
    {
        return $this->currencyConverter !== null
            && $this->exchangeRates?->findOneWithCurrencyPair($base, $target) !== null;
    }
}
