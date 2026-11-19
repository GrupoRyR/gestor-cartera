<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Support\Calendar\ColombianHolidays;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * When the next run of a schedule happens, in the timezone of $now.
 */
final class RunCalendar
{
    /**
     * The next $dayOfWeek (0 = Sunday … 6 = Saturday) at $time ("HH:MM"),
     * today when that time has not passed yet.
     */
    public static function nextWeekly(CarbonImmutable $now, int $dayOfWeek, string $time): CarbonImmutable
    {
        [$hour, $minute] = self::time($time);

        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException("The day of week must be 0 to 6, {$dayOfWeek} given.");
        }

        $candidate = $now->setTime($hour, $minute)->addDays(($dayOfWeek - $now->dayOfWeek + 7) % 7);

        return $candidate->lessThan($now) ? $candidate->addWeek() : $candidate;
    }

    /**
     * The next $n-th business day of a month (ColombianHolidays) at $time,
     * today when it is that day and the time has not passed yet.
     */
    public static function nextBusinessDayOfMonth(CarbonImmutable $now, int $n, string $time, ColombianHolidays $holidays): CarbonImmutable
    {
        $candidate = self::businessDayOfMonth($now, $n, $time, $holidays);

        return $candidate->lessThan($now) ? self::businessDayOfMonth($now, $n, $time, $holidays, 1) : $candidate;
    }

    /**
     * The $n-th business day of the month of $now (or $monthsAhead later) at
     * $time, in the timezone of $now.
     */
    public static function businessDayOfMonth(CarbonImmutable $now, int $n, string $time, ColombianHolidays $holidays, int $monthsAhead = 0): CarbonImmutable
    {
        [$hour, $minute] = self::time($time);
        $month = $now->startOfMonth()->addMonthsNoOverflow($monthsAhead);

        return $holidays->nthBusinessDay($month->year, $month->month, $n, $now->getTimezone()->getName())->setTime($hour, $minute);
    }

    /**
     * @return array{int, int}
     */
    private static function time(string $time): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new InvalidArgumentException("[{$time}] is not a HH:MM time.");
        }

        return [(int) $parts[1], (int) $parts[2]];
    }
}
