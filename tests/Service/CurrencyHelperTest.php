<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\CurrencyHelper;
use PHPUnit\Framework\TestCase;

class CurrencyHelperTest extends TestCase
{
    public function testStandardCurrencyDividesByHundred(): void
    {
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'EUR'));
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'USD'));
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'GBP'));
        $this->assertSame(0.01, CurrencyHelper::toCurrencyUnits(1, 'EUR'));
        $this->assertSame(0.0, CurrencyHelper::toCurrencyUnits(0, 'EUR'));
    }

    /**
     * Sylius stores every currency in hundredths (MoneyType divisor 100,
     * MoneyFormatter / 100), so 1,000 JPY is 100000. Must never be sent as
     * 100,000 JPY again.
     */
    public function testZeroDecimalCurrencyIsStoredInHundredthsToo(): void
    {
        $this->assertSame(1000.0, CurrencyHelper::toCurrencyUnits(100000, 'JPY'));
        $this->assertSame(1999.0, CurrencyHelper::toCurrencyUnits(199900, 'KRW'));
        $this->assertSame(1000.0, CurrencyHelper::toCurrencyUnits(99950, 'JPY'));
    }

    public function testThreeDecimalCurrencyIsStoredInHundredthsToo(): void
    {
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'KWD'));
        $this->assertSame(1.5, CurrencyHelper::toCurrencyUnits(150, 'OMR'));
    }

    public function testCurrencyCodeIsCaseInsensitive(): void
    {
        $this->assertSame(1000.0, CurrencyHelper::toCurrencyUnits(100000, 'jpy'));
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'eur'));
    }

    public function testEmptyCurrencyCodeDefaultsToStandard(): void
    {
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, ''));
    }

    public function testNegativeAmounts(): void
    {
        $this->assertSame(-19.99, CurrencyHelper::toCurrencyUnits(-1999, 'EUR'));
        $this->assertSame(-1999.0, CurrencyHelper::toCurrencyUnits(-199900, 'JPY'));
    }
}
