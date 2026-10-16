<?php

namespace App\Modules\Comisiones\Domain\Shared;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Strict parsing of the decimal numbers the engine receives (amounts, rates,
 * factors). Only plain decimals are accepted: an optional minus sign, digits
 * and an optional fractional part ("-1234.56"). Thousand separators, decimal
 * commas, exponents and fractions are rejected so a badly mapped SAP value
 * never turns silently into a different amount.
 */
final class Decimals
{
    private const PLAIN = '/^-?\d+(\.\d+)?$/';

    /**
     * @throws InvalidArgumentException when a string is not a plain decimal.
     */
    public static function parse(BigDecimal|int|string $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_string($value) && preg_match(self::PLAIN, $value) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a plain decimal number.");
        }

        return BigDecimal::of($value);
    }
}
