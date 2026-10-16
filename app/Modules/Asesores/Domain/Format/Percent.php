<?php

namespace App\Modules\Asesores\Domain\Format;

/**
 * A percentage as the screens and exports print it: "12,3 %", with a comma
 * for decimals, a dot for thousands and a no-break space before the sign.
 */
final class Percent
{
    public static function format(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.')."\u{00A0}%";
    }
}
