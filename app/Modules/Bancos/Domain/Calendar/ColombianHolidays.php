<?php

namespace App\Modules\Bancos\Domain\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Colombian public holidays (festivos) and business days, computed by
 * law, so no list has to be kept up to date:
 *
 * - Fixed: Jan 1, May 1, Jul 20, Aug 7, Dec 8 and Dec 25.
 * - Moved to the following Monday when they do not fall on one (Ley 51 de
 *   1983): Jan 6, Mar 19, Jun 29, Aug 15, Oct 12, Nov 1 and Nov 11.
 * - From Easter Sunday: Holy Thursday and Good Friday, and on Mondays
 *   Ascension (+43 days), Corpus Christi (+64) and Sacred Heart (+71).
 *
 * A business day is Monday to Friday and not a holiday. Dates are compared
 * as calendar days, in the time zone of the date given.
 */
final class ColombianHolidays
{
    private const FIXED = ['01-01', '05-01', '07-20', '08-07', '12-08', '12-25'];

    private const MOVED_TO_MONDAY = ['01-06', '03-19', '06-29', '08-15', '10-12', '11-01', '11-11'];

    /** Days from Easter Sunday: Holy Thursday, Good Friday, Ascension, Corpus Christi, Sacred Heart. */
    private const FROM_EASTER = [-3, -2, 43, 64, 71];

    /** @var array<int, array<string, true>> Year => set of Y-m-d. */
    private array $years = [];

    /**
     * The holidays of a year as Y-m-d, in date order.
     *
     * @return list<string>
     */
    public function forYear(int $year): array
    {
        return array_keys($this->holidaysOf($year));
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        return isset($this->holidaysOf($date->year)[$date->toDateString()]);
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && ! $this->isHoliday($date);
    }

    /**
     * The first business day after $date (not $date itself).
     */
    public function nextBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        do {
            $date = $date->addDay();
        } while (! $this->isBusinessDay($date));

        return $date;
    }

    /**
     * The last business day before $date (not $date itself).
     */
    public function previousBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        do {
            $date = $date->subDay();
        } while (! $this->isBusinessDay($date));

        return $date;
    }

    /**
     * Easter Sunday of the Gregorian calendar, by the anonymous algorithm
     * (Meeus/Jones/Butcher), so ext-calendar's easter_date() is not needed.
     */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * @return array<string, true>
     */
    private function holidaysOf(int $year): array
    {
        if (isset($this->years[$year])) {
            return $this->years[$year];
        }

        $dates = [];

        foreach (self::FIXED as $monthDay) {
            $dates[] = CarbonImmutable::parse("{$year}-{$monthDay}");
        }

        foreach (self::MOVED_TO_MONDAY as $monthDay) {
            $date = CarbonImmutable::parse("{$year}-{$monthDay}");
            $dates[] = $date->isMonday() ? $date : $date->next(CarbonInterface::MONDAY);
        }

        $easter = self::easter($year);

        foreach (self::FROM_EASTER as $days) {
            $dates[] = $easter->addDays($days);
        }

        $set = array_fill_keys(array_map(fn (CarbonImmutable $date): string => $date->toDateString(), $dates), true);
        ksort($set);

        return $this->years[$year] = $set;
    }
}
