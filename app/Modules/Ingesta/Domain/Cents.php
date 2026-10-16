<?php

namespace App\Modules\Ingesta\Domain;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact peso amounts as integer cents, for the cartera totals of a load.
 *
 * Rows carry amounts as decimal strings ("1234567.89"); adding integer
 * cents keeps the sums exact with no float drift and no extension. The
 * range is that of a decimal(18, 2) column: 16 integer digits, which fit a
 * 64-bit integer of cents.
 */
final class Cents
{
    private const string DECIMAL = '/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/';

    /**
     * Cents of a decimal string with a dot, no sign other than "-", no
     * thousands separator and at most two decimals.
     *
     * @throws InvalidArgumentException When the string is not such a decimal.
     */
    public static function fromDecimal(string $decimal): int
    {
        if (preg_match(self::DECIMAL, $decimal, $parts) !== 1) {
            throw new InvalidArgumentException("[{$decimal}] is not a decimal amount with at most 2 places.");
        }

        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return $parts[1] === '-' ? -$cents : $cents;
    }

    /**
     * @param  iterable<int>  $amounts
     *
     * @throws OverflowException When the sum leaves the 64-bit integer range.
     */
    public static function sum(iterable $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /**
     * @throws OverflowException When the sum leaves the 64-bit integer range.
     */
    public static function add(int $a, int $b): int
    {
        // Checked before adding: PHP would silently turn the sum into a float.
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new OverflowException('The amount is out of the supported range.');
        }

        return $a + $b;
    }

    /**
     * The decimal string of an amount, always with two places, e.g. "-1234.50".
     */
    public static function toDecimal(int $cents): string
    {
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * The amount in pesos, for LoadRecord::$total and $overdue.
     */
    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }
}
