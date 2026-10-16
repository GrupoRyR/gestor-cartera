<?php

namespace App\Modules\Asesores\Domain\Chart;

/**
 * The length of a bar against the biggest one of its chart.
 */
final class BarWidth
{
    /**
     * A bar with money in it never draws thinner than this, in percent.
     */
    public const float MIN = 1.5;

    /**
     * Percent of the track the bar fills, 0 to 100; 0 for no value. The
     * length follows the size of the amount, whatever its sign.
     */
    public static function of(float $value, float $biggest): float
    {
        if ($value == 0.0 || $biggest == 0.0) {
            return 0.0;
        }

        return max(self::MIN, round(abs($value) / abs($biggest) * 100, 1));
    }
}
