<?php

namespace App\Modules\Asesores\Application\Commitments;

use App\Modules\Asesores\Domain\Format\PesosInput;

/**
 * An amount offered to the "Nuevo compromiso" form of the client file, e.g.
 * the balance a partial payment left: carried by the link of the notice as
 * ?valor= and printed clean in the money input, which formats it.
 */
final class SuggestedAmount
{
    /** The largest value the form accepts (Http\Rules\PesosAmount::MAX). */
    private const float MAX = 999_999_999_999.99;

    /**
     * The amount for the link: digits with no thousands separators, and
     * decimals only when there are cents ("1500000", "1500000.5"). Null when
     * it is not a number above zero that a commitment could have.
     */
    public static function forLink(mixed $amount): ?string
    {
        if (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount))) {
            return null;
        }

        $amount = round((float) $amount, 2);

        if (! self::fits($amount)) {
            return null;
        }

        return floor($amount) === $amount ? number_format($amount, 0, '.', '') : rtrim(number_format($amount, 2, '.', ''), '0');
    }

    /**
     * The ?valor= of the client file as the value of the money input: the
     * clean amount, cents included ("1500000", "1500000.5"), which the field
     * shows as 1.500.000 or 1.500.000,5. The query may also be written as in
     * Colombia ("1.500.000,50"). Null when it is not an amount above zero.
     */
    public static function forField(mixed $query): ?string
    {
        $amount = is_string($query) ? PesosInput::parse($query) : null;

        return $amount === null ? null : self::forLink($amount);
    }

    private static function fits(float $amount): bool
    {
        return is_finite($amount) && $amount > 0 && $amount <= self::MAX;
    }
}
