<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The weekly accumulated collections (acumulado semanal de ingresos): the
 * seven days of the ISO week (Monday to Sunday) with what came in each day and
 * the running total, and how the week compares with the one before.
 *
 * Statements arrive the day after (día vencido), so a day after $lastLoaded
 * is not "zero pesos", it is "not here yet": it carries no figure and does
 * not enter the comparison with the previous week.
 */
final readonly class WeekReport
{
    /**
     * @param  list<WeekDay>  $days  Monday to Sunday.
     * @param  array<string, float>  $companyTotals  Company value => pesos, for the companies in view.
     */
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $days,
        public float $total,
        public array $companyTotals,
        public float $previousTotal,
        public int $daysCounted,
    ) {}

    /**
     * @param  list<DailyCollection>  $thisWeek  Daily collections of the week.
     * @param  list<DailyCollection>  $previousWeek  Daily collections of the week before; compared over the same weekdays.
     * @param  list<CompanyCode>  $companies  The companies in view.
     * @param  CarbonImmutable  $lastLoaded  The last day whose statements can be here: yesterday.
     */
    public static function from(CarbonImmutable $anyDayOfTheWeek, array $thisWeek, array $previousWeek, array $companies, CarbonImmutable $lastLoaded): self
    {
        $start = $anyDayOfTheWeek->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $companies = array_values(array_filter(CompanyCode::cases(), fn (CompanyCode $code): bool => in_array($code, $companies, true)));

        $perDay = [];
        $companyTotals = array_fill_keys(array_map(fn (CompanyCode $code): string => $code->value, $companies), 0.0);

        foreach ($thisWeek as $day) {
            if (! in_array($day->company, $companies, true)) {
                continue;
            }

            $perDay[$day->date->toDateString()] = ($perDay[$day->date->toDateString()] ?? 0.0) + $day->total;
            $companyTotals[$day->company->value] += $day->total;
        }

        $days = [];
        $running = 0.0;

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $start->addDays($offset);
            $loaded = $date->toDateString() <= $lastLoaded->toDateString();
            $total = $perDay[$date->toDateString()] ?? 0.0;
            $running += $total;

            $days[] = new WeekDay($date, $loaded, $total, $loaded ? $running : null);
        }

        $daysCounted = count(array_filter($days, fn (WeekDay $day): bool => $day->loaded));

        return new self(
            start: $start,
            end: $start->addDays(6),
            days: $days,
            total: array_sum($companyTotals),
            companyTotals: $companyTotals,
            previousTotal: self::sum($previousWeek, $companies, $start->subWeek(), $daysCounted),
            daysCounted: $daysCounted,
        );
    }

    /**
     * The biggest day, to size the bars.
     */
    public function peak(): float
    {
        return max(0.0, ...array_map(fn (WeekDay $day): float => $day->total, $this->days));
    }

    /**
     * Width of the bar of a day, 0 to 100: a day with money always shows a sliver.
     */
    public function barWidth(WeekDay $day): float
    {
        $peak = $this->peak();

        if ($peak <= 0 || $day->total <= 0) {
            return 0.0;
        }

        return max(2.0, round($day->total / $peak * 100, 1));
    }

    /**
     * Up, down or flat against the week before; null while the comparison
     * means nothing (no figures in either week, or the week has not started).
     */
    public function trend(): ?string
    {
        if ($this->daysCounted === 0 || ($this->total <= 0 && $this->previousTotal <= 0)) {
            return null;
        }

        return match (true) {
            $this->total > $this->previousTotal => 'up',
            $this->total < $this->previousTotal => 'down',
            default => 'flat',
        };
    }

    /**
     * Percent change against the week before; null when there is nothing to compare.
     */
    public function change(): ?float
    {
        if ($this->daysCounted === 0 || $this->previousTotal <= 0) {
            return null;
        }

        return round(($this->total - $this->previousTotal) / $this->previousTotal * 100, 1);
    }

    /**
     * What came in during the first $weekdays days of the week that starts on
     * $start: the previous week is compared over the same weekdays as the
     * days this week already has, so a week in progress is not "down" just
     * for being unfinished.
     *
     * @param  list<DailyCollection>  $days
     * @param  list<CompanyCode>  $companies
     */
    private static function sum(array $days, array $companies, CarbonImmutable $start, int $weekdays): float
    {
        $last = $start->addDays($weekdays - 1)->toDateString();

        return array_sum(array_map(
            fn (DailyCollection $day): float => in_array($day->company, $companies, true) && $day->date->toDateString() <= $last ? $day->total : 0.0,
            $days,
        ));
    }
}
