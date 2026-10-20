<?php

namespace App\Modules\Cobro\Domain\Runs;

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
     * The next $dayOfMonth (1 to 28, so every month has it) at $time.
     */
    public static function nextMonthly(CarbonImmutable $now, int $dayOfMonth, string $time): CarbonImmutable
    {
        [$hour, $minute] = self::time($time);

        if ($dayOfMonth < 1 || $dayOfMonth > 28) {
            throw new InvalidArgumentException("The day of month must be 1 to 28, {$dayOfMonth} given.");
        }

        $candidate = $now->setDate($now->year, $now->month, $dayOfMonth)->setTime($hour, $minute);

        return $candidate->lessThan($now) ? $candidate->addMonthNoOverflow() : $candidate;
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
