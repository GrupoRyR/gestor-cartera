<?php

namespace App\Modules\Asesores\Domain\Portfolio;

use Carbon\CarbonImmutable;

/**
 * How old the receivables snapshot on screen is. The robot publishes every
 * working day, so an old cutoff means the numbers may be out of date.
 */
final class CutoffAge
{
    /**
     * Calendar days from the cutoff to today; negative for a cutoff in the
     * future. Only the dates count, not the hours.
     */
    public static function days(CarbonImmutable $cutoff, CarbonImmutable $today): int
    {
        return (int) $cutoff->startOfDay()->diffInDays($today->startOfDay(), false);
    }

    /**
     * Whether the cutoff is older than $afterDays. No cutoff is not stale: the
     * screen shows its empty state instead.
     */
    public static function isStale(?CarbonImmutable $cutoff, CarbonImmutable $today, int $afterDays): bool
    {
        return $cutoff !== null && self::days($cutoff, $today) > $afterDays;
    }
}
