<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Decimals;
use InvalidArgumentException;

/**
 * Checks shared by the SAP lines (sales, payments and crossings): every line
 * has a natural key (§9.5) and reports its currency and exchange rate
 * ("Moneda" and "TRM", §14.7).
 */
final class LineFields
{
    /**
     * @throws InvalidArgumentException when the key or the currency is blank,
     *                                  or the exchange rate is not a positive plain decimal.
     */
    public static function assertValid(string $key, string $currency, string $exchangeRate): void
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A SAP line needs its natural key.');
        }

        if (trim($currency) === '') {
            throw new InvalidArgumentException("Line {$key} needs a currency.");
        }

        if (! Decimals::parse($exchangeRate)->isPositive()) {
            throw new InvalidArgumentException("Line {$key} needs a positive exchange rate, got {$exchangeRate}.");
        }
    }

    /** Whether the amounts are not plain pesos: another currency, or an exchange rate other than 1. */
    public static function isForeign(string $currency, string $exchangeRate): bool
    {
        return strtoupper(trim($currency)) !== 'COP' || ! Decimals::parse($exchangeRate)->isEqualTo(1);
    }
}
