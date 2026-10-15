<?php

namespace App\Modules\Core\Application\Portfolio;

use InvalidArgumentException;

/**
 * Exact peso amounts as integer cents.
 *
 * The robot sends amounts as decimal strings ("1234567.89") and MySQL reads
 * decimal columns back as strings, while SQLite reads them as numbers.
 * Adding cents keeps sums exact on both engines, with no float drift and no
 * extension (a decimal(18,2) fits in a 64-bit integer of cents).
 */
final class Cents
{
    private const string DECIMAL = '/^([+-]?)(\d*)(?:\.(\d*))?$/';

    /**
     * Cents of a decimal value: a string with a dot and no thousands
     * separator, an integer, a float (as SQLite returns decimals) or null
     * (zero). A third decimal rounds half away from zero.
     *
     * @throws InvalidArgumentException When a string is not a decimal number.
     */
    public static function fromDecimal(int|float|string|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        $value = trim($value);

        if (preg_match(self::DECIMAL, $value, $parts) !== 1 || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
            throw new InvalidArgumentException("[{$value}] is not a decimal amount.");
        }

        $fraction = str_pad($parts[3] ?? '', 3, '0');
        $cents = (int) $parts[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $parts[1] === '-' ? -$cents : $cents;
    }

    /**
     * The decimal string of an amount in cents, e.g. "-1234.50".
     */
    public static function toDecimal(int $cents): string
    {
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * The amount in pesos, for the status rules and the contracts.
     */
    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }
}
