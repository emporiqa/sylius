<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Service\PriceEntryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Currency\Converter\CurrencyConverterInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Currency\Model\ExchangeRateInterface;
use Sylius\Component\Currency\Repository\ExchangeRateRepositoryInterface;
use Sylius\Component\Taxation\Calculator\DefaultCalculator;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;

/**
 * Price entries are what the storefront shows and the cart charges: Sylius's
 * own calculator, exchange rate and tax rate, never a parallel formula.
 */
class PriceEntryBuilderTest extends TestCase
{
    private function currency(string $code): CurrencyInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($code);

        return $currency;
    }

    private function channel(?ZoneInterface $zone = null): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getBaseCurrency')->willReturn($this->currency('USD'));
        $channel->method('getCurrencies')->willReturn(new ArrayCollection([
            $this->currency('USD'), $this->currency('EUR'), $this->currency('GBP'),
        ]));
        $channel->method('getDefaultTaxZone')->willReturn($zone);

        return $channel;
    }

    private function variant(int $price = 2000, ?int $original = 2500): ProductVariantInterface
    {
        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn($price);
        $pricing->method('getOriginalPrice')->willReturn($original);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);

        return $variant;
    }

    /** Converts USD to EUR at 0.92; no GBP rate exists. */
    private function converter(): array
    {
        $converter = $this->createMock(CurrencyConverterInterface::class);
        $converter->method('convert')->willReturnCallback(
            fn (int $value, string $from, string $to): int => (int) round($value * 0.92),
        );
        $rates = $this->createMock(ExchangeRateRepositoryInterface::class);
        $rates->method('findOneWithCurrencyPair')->willReturnCallback(
            fn (string $a, string $b) => $b === 'EUR' ? $this->createMock(ExchangeRateInterface::class) : null,
        );

        return [$converter, $rates];
    }

    private function taxResolver(float $amount, bool $included): TaxRateResolverInterface
    {
        $rate = $this->createMock(TaxRateInterface::class);
        $rate->method('getAmount')->willReturn($amount);
        $rate->method('isIncludedInPrice')->willReturn($included);
        $rate->method('getCalculator')->willReturn('default');
        $resolver = $this->createMock(TaxRateResolverInterface::class);
        $resolver->method('resolve')->willReturn($rate);

        return $resolver;
    }

    public function testWithoutServicesTheStoredChannelPricingIsUsed(): void
    {
        $entries = (new PriceEntryBuilder())->entries($this->channel(), $this->variant(1999, null));

        $this->assertSame([['currency' => 'USD', 'current_price' => 19.99, 'regular_price' => 19.99]], $entries);
    }

    public function testThePriceComesFromSyliusCalculatorSoDecoratorsCount(): void
    {
        $calculator = $this->createMock(ProductVariantPricesCalculatorInterface::class);
        $calculator->method('calculate')->willReturn(1500);
        $calculator->method('calculateOriginal')->willReturn(2500);

        $entries = (new PriceEntryBuilder($calculator))->entries($this->channel(), $this->variant());

        $this->assertSame(15.0, $entries[0]['current_price']);
        $this->assertSame(25.0, $entries[0]['regular_price']);
    }

    public function testOtherChannelCurrenciesAreConvertedOnlyWithAnExchangeRate(): void
    {
        [$converter, $rates] = $this->converter();

        $entries = (new PriceEntryBuilder(null, $converter, $rates))->entries($this->channel(), $this->variant());

        $this->assertSame(['USD', 'EUR'], array_column($entries, 'currency'), 'GBP has no rate: Sylius would show USD amounts');
        $this->assertSame(['currency' => 'EUR', 'current_price' => 18.4, 'regular_price' => 23.0], $entries[1]);
    }

    public function testAQuoteFallsBackToTheBaseCurrency(): void
    {
        [$converter, $rates] = $this->converter();
        $builder = new PriceEntryBuilder(null, $converter, $rates);

        $this->assertSame('EUR', $builder->quoteCurrency($this->channel(), 'eur'));
        $this->assertSame('USD', $builder->quoteCurrency($this->channel(), 'GBP'));
        $this->assertSame('USD', $builder->quoteCurrency($this->channel(), 'JPY'));
        $this->assertSame('USD', $builder->quoteCurrency($this->channel(), ''));
    }

    public function testTaxIncludedInThePriceIsTakenOut(): void
    {
        $zone = $this->createMock(ZoneInterface::class);
        $builder = new PriceEntryBuilder(null, null, null, $this->taxResolver(0.23, true), new DefaultCalculator());

        $entry = $builder->entries($this->channel($zone), $this->variant(1999))[0];

        // round(1999 - 1999 / 1.23) = 374, as Sylius's DefaultCalculator gives it.
        $this->assertSame(19.99, $entry['price_incl_tax']);
        $this->assertSame(16.25, $entry['price_excl_tax']);
        $this->assertTrue($builder->includesTax($this->variant(), $zone));
    }

    public function testTaxNotIncludedIsAddedLikeTheCart(): void
    {
        $zone = $this->createMock(ZoneInterface::class);
        $builder = new PriceEntryBuilder(null, null, null, $this->taxResolver(0.2, false), new DefaultCalculator());

        $entry = $builder->entries($this->channel($zone), $this->variant(1999))[0];

        $this->assertSame(19.99, $entry['current_price']);
        $this->assertSame(23.99, $entry['price_incl_tax']);
        $this->assertSame(19.99, $entry['price_excl_tax']);
        $this->assertFalse($builder->includesTax($this->variant(), $zone));
    }

    public function testWithoutATaxZoneNoTaxSplitIsClaimed(): void
    {
        $builder = new PriceEntryBuilder(null, null, null, $this->taxResolver(0.2, false), new DefaultCalculator());

        $entry = $builder->entries($this->channel(), $this->variant())[0];

        $this->assertArrayNotHasKey('price_incl_tax', $entry);
        $this->assertArrayNotHasKey('price_excl_tax', $entry);
    }

    public function testAVariantWithoutAPriceInTheChannelHasNoEntry(): void
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getChannelPricingForChannel')->willReturn(null);

        $this->assertSame([], (new PriceEntryBuilder())->entries($this->channel(), $variant));
        $this->assertSame([], (new PriceEntryBuilder())->entries($this->channel(), null));
    }
}
