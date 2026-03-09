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

    public function testZeroDecimalCurrencyNoConversion(): void
    {
        $this->assertSame(1999.0, CurrencyHelper::toCurrencyUnits(1999, 'JPY'));
        $this->assertSame(1999.0, CurrencyHelper::toCurrencyUnits(1999, 'KRW'));
        $this->assertSame(500.0, CurrencyHelper::toCurrencyUnits(500, 'VND'));
    }

    public function testThreeDecimalCurrencyDividesByThousand(): void
    {
        $this->assertSame(1.999, CurrencyHelper::toCurrencyUnits(1999, 'KWD'));
        $this->assertSame(1.999, CurrencyHelper::toCurrencyUnits(1999, 'BHD'));
        $this->assertSame(1.500, CurrencyHelper::toCurrencyUnits(1500, 'OMR'));
    }

    public function testCurrencyCodeIsCaseInsensitive(): void
    {
        $this->assertSame(1999.0, CurrencyHelper::toCurrencyUnits(1999, 'jpy'));
        $this->assertSame(1.999, CurrencyHelper::toCurrencyUnits(1999, 'kwd'));
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, 'eur'));
    }

    public function testEmptyCurrencyCodeDefaultsToStandard(): void
    {
        $this->assertSame(19.99, CurrencyHelper::toCurrencyUnits(1999, ''));
    }

    public function testNegativeAmounts(): void
    {
        $this->assertSame(-19.99, CurrencyHelper::toCurrencyUnits(-1999, 'EUR'));
        $this->assertSame(-1999.0, CurrencyHelper::toCurrencyUnits(-1999, 'JPY'));
    }
}
