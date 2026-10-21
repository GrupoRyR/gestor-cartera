<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Bancos\Domain\Calendar\ColombianHolidays;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The daily collections of a month as the portfolio analyst keeps them in
 * her workbook: one grid per company, a row per Monday-to-Friday week, a
 * column per weekday, the business days of each week, and the totals of each
 * row, each column and the month.
 *
 * Only business days carry money: the credits of a Saturday, a Sunday or a
 * holiday are added to the next business day, so a cell holds what came in
 * from the day after the previous business day up to its own day. That can
 * cross months: a Saturday at the end of a month belongs to the first
 * business day of the next one.
 *
 * Statements arrive the day after (día vencido), so a business day after
 * $lastLoaded is not "zero pesos", it is "not here yet"; whatever is already
 * stored for it (a statement carries the first movements of the next day)
 * still counts, so the month never leaves money out. Everything is added
 * in cents and divided once at the end, so the totals are exact.
 */
final readonly class MonthReport
{
    private const WEEKDAYS = 5;

    /**
     * @param  CarbonImmutable  $month  First day of the month.
     * @param  list<MonthGrid>  $grids  One per company in view, in CompanyCode order; each counts the business days.
     */
    private function __construct(
        public CarbonImmutable $month,
        public array $grids,
    ) {}

    /**
     * @param  CarbonImmutable  $anyDayOfTheMonth  Any day of the month to draw.
     * @param  list<DailyCollection>  $days  What the provider gave for range(); anything else is left out.
     * @param  list<CompanyCode>  $companies  The companies in view.
     * @param  CarbonImmutable  $lastLoaded  The last day whose statements can be here: yesterday.
     */
    public static function from(CarbonImmutable $anyDayOfTheMonth, array $days, array $companies, CarbonImmutable $lastLoaded, ColombianHolidays $holidays): self
    {
        $month = $anyDayOfTheMonth->startOfMonth()->startOfDay();
        $companies = array_values(array_filter(CompanyCode::cases(), fn (CompanyCode $code): bool => in_array($code, $companies, true)));
        [$from, $to] = self::range($month, $holidays);

        /** @var array<string, array<string, int>> $cents Company value => business day (Y-m-d) => cents. */
        $cents = [];

        foreach ($days as $day) {
            $date = $day->date->toDateString();

            if ($date < $from->toDateString() || $date > $to->toDateString() || ! in_array($day->company, $companies, true)) {
                continue;
            }

            $businessDay = ($holidays->isBusinessDay($day->date) ? $day->date : $holidays->nextBusinessDay($day->date))->toDateString();
            $cents[$day->company->value][$businessDay] = ($cents[$day->company->value][$businessDay] ?? 0) + (int) round($day->total * 100);
        }

        $grids = array_map(
            fn (CompanyCode $company): MonthGrid => self::grid($company, $month, $cents[$company->value] ?? [], $lastLoaded, $holidays),
            $companies,
        );

        return new self($month, $grids);
    }

    /**
     * The days to ask the provider for: from the day after the last business
     * day before the month (so its weekend and holidays come in) to the last
     * business day of the month (a weekend after it belongs to the next one).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(CarbonImmutable $anyDayOfTheMonth, ColombianHolidays $holidays): array
    {
        $first = $anyDayOfTheMonth->startOfMonth()->startOfDay();
        $last = $anyDayOfTheMonth->endOfMonth()->startOfDay();

        $firstBusinessDay = $holidays->isBusinessDay($first) ? $first : $holidays->nextBusinessDay($first);
        $lastBusinessDay = $holidays->isBusinessDay($last) ? $last : $holidays->previousBusinessDay($last);

        return [$holidays->previousBusinessDay($firstBusinessDay)->addDay(), $lastBusinessDay];
    }

    /**
     * @param  array<string, int>  $cents  Business day (Y-m-d) => cents.
     */
    private static function grid(CompanyCode $company, CarbonImmutable $month, array $cents, CarbonImmutable $lastLoaded, ColombianHolidays $holidays): MonthGrid
    {
        $weeks = [];
        $columnCents = array_fill(0, self::WEEKDAYS, 0);
        $monthCents = 0;
        $businessDays = 0;

        $lastDay = $month->endOfMonth()->startOfDay();

        for ($monday = $month->startOfWeek(CarbonInterface::MONDAY); $monday->lessThanOrEqualTo($lastDay); $monday = $monday->addWeek()) {
            $cells = [];
            $inMonth = [];
            $weekCents = 0;
            $weekBusinessDays = 0;

            for ($offset = 0; $offset < self::WEEKDAYS; $offset++) {
                $date = $monday->addDays($offset);
                $state = self::stateOf($date, $month, $lastLoaded, $holidays);
                $amount = in_array($state, [MonthCellState::Loaded, MonthCellState::Pending], true) ? ($cents[$date->toDateString()] ?? 0) : 0;

                if ($state !== MonthCellState::Outside) {
                    $inMonth[] = $date;
                }

                if ($state === MonthCellState::Pending || $state === MonthCellState::Loaded) {
                    $weekBusinessDays++;
                }

                $weekCents += $amount;
                $columnCents[$offset] += $amount;
                $cells[] = new MonthCell($date, $state, $amount / 100.0);
            }

            if ($inMonth === []) {
                continue;
            }

            $monthCents += $weekCents;
            $businessDays += $weekBusinessDays;
            $weeks[] = new MonthWeek($inMonth[0], $inMonth[count($inMonth) - 1], $weekBusinessDays, $cells, $weekCents / 100.0);
        }

        return new MonthGrid(
            company: $company,
            weeks: $weeks,
            columnTotals: array_map(fn (int $amount): float => $amount / 100.0, $columnCents),
            total: $monthCents / 100.0,
            businessDays: $businessDays,
        );
    }

    private static function stateOf(CarbonImmutable $date, CarbonImmutable $month, CarbonImmutable $lastLoaded, ColombianHolidays $holidays): MonthCellState
    {
        return match (true) {
            $date->format('Y-m') !== $month->format('Y-m') => MonthCellState::Outside,
            $holidays->isHoliday($date) => MonthCellState::Holiday,
            $date->toDateString() > $lastLoaded->toDateString() => MonthCellState::Pending,
            default => MonthCellState::Loaded,
        };
    }
}
