<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

class CurrencyHelper
{
    // Rounded to the currency's own decimals, as Sylius's MoneyFormatter
    // displays them (NumberFormatter rounds JPY to whole yen).
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Sylius stores every amount in hundredths whatever the currency: its
     * MoneyType form uses divisor 100 and MoneyFormatter divides by 100, so
     * a 1,000 JPY price is stored as 100000. Dividing by the currency's ISO
     * minor unit instead sent that price as 100,000 JPY (and KWD prices ten
     * times too small).
     */
    public static function toCurrencyUnits(int $minorUnits, string $currencyCode): float
    {
        $decimals = in_array(strtoupper($currencyCode), self::ZERO_DECIMAL, true) ? 0 : 2;

        return round($minorUnits / 100, $decimals);
    }
}
