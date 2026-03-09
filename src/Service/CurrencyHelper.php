<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

class CurrencyHelper
{
    // Currencies with non-standard (non-2) decimal places
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private const THREE_DECIMAL = [
        'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
    ];

    public static function toCurrencyUnits(int $minorUnits, string $currencyCode): float
    {
        $divisor = self::getDivisor($currencyCode);

        return round($minorUnits / $divisor, self::getDecimalPlaces($currencyCode));
    }

    private static function getDivisor(string $code): int
    {
        $code = strtoupper($code);

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 1;
        }

        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 1000;
        }

        return 100;
    }

    private static function getDecimalPlaces(string $code): int
    {
        $code = strtoupper($code);

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }
}
