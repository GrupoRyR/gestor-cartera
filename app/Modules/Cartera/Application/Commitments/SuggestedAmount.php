<?php

namespace App\Modules\Cartera\Application\Commitments;

/**
 * An amount offered to the "Nuevo compromiso" form (?valor=), e.g. the
 * balance a partial payment left: the digits the value field expects, with
 * no thousands separators, and decimals only when there are cents.
 */
final class SuggestedAmount
{
    /** The largest value StoreCommitmentRequest accepts. */
    private const MAX = 9_999_999_999;

    /**
     * The amount as the field's value; null when it is not a number above
     * zero that a commitment could have.
     */
    public static function from(mixed $amount): ?string
    {
        if (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount))) {
            return null;
        }

        $amount = round((float) $amount, 2);

        if (! is_finite($amount) || $amount <= 0 || $amount > self::MAX) {
            return null;
        }

        return floor($amount) === $amount ? (string) (int) $amount : number_format($amount, 2, '.', '');
    }
}
