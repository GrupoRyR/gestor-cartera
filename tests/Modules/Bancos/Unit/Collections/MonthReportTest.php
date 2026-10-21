<?php

use App\Modules\Bancos\Domain\Calendar\ColombianHolidays;
use App\Modules\Bancos\Domain\Collections\MonthCell;
use App\Modules\Bancos\Domain\Collections\MonthCellState;
use App\Modules\Bancos\Domain\Collections\MonthReport;
use App\Modules\Bancos\Domain\Collections\MonthWeek;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

function bancosGridDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
}

function bancosGridCredit(string $date, float $total, CompanyCode $company = CompanyCode::SGX): DailyCollection
{
    return new DailyCollection(bancosGridDay($date), $company, $total, ['bogota' => $total], 1);
}

/**
 * The grid of a month as the report builds it, yesterday being $lastLoaded.
 *
 * @param  list<DailyCollection>  $days
 * @param  list<CompanyCode>  $companies
 */
function bancosMonth(string $month, array $days, string $lastLoaded = '2026-10-15', array $companies = [CompanyCode::SGX]): MonthReport
{
    return MonthReport::from(bancosGridDay($month), $days, $companies, bancosGridDay($lastLoaded), new ColombianHolidays);
}

/**
 * @return list<string>
 */
function bancosStates(MonthWeek $week): array
{
    return array_map(fn (MonthCell $cell): string => $cell->state->name, $week->cells);
}

/**
 * @return list<float>
 */
function bancosAmounts(MonthWeek $week): array
{
    return array_map(fn (MonthCell $cell): float => $cell->amount, $week->cells);
}

/**
 * October 2026 as María Paula's sheet would have it on Friday the 16th:
 * Thursday the 1st opens the month, Monday the 12th is a holiday and the
 * statements of the 16th are not here yet.
 *
 * @return list<DailyCollection>
 */
function bancosOctober(): array
{
    return [
        bancosGridCredit('2026-09-30', 999),      // September: not in the grid
        bancosGridCredit('2026-10-01', 100.10),
        bancosGridCredit('2026-10-02', 200.20),
        bancosGridCredit('2026-10-03', 5),        // Saturday: goes to Monday the 5th
        bancosGridCredit('2026-10-04', 7),        // Sunday: goes to Monday the 5th
        bancosGridCredit('2026-10-05', 10),
        bancosGridCredit('2026-10-07', 0.10),
        bancosGridCredit('2026-10-08', 0.20),
        bancosGridCredit('2026-10-10', 1),        // Saturday before the holiday: goes to Tuesday the 13th
        bancosGridCredit('2026-10-12', 3),        // the holiday itself: goes to Tuesday the 13th
        bancosGridCredit('2026-10-13', 4),
        bancosGridCredit('2026-10-31', 50),       // Saturday: belongs to Tuesday 3 November
        bancosGridCredit('2026-10-05', 40, CompanyCode::SDC),
    ];
}

describe('the weeks of the month', function () {
    it('has a row per Monday-to-Friday week with weekdays in the month, labelled by its first and last', function () {
        $grid = bancosMonth('2026-10-16', bancosOctober())->grids[0];

        expect(array_map(fn (MonthWeek $week): string => $week->label(), $grid->weeks))->toBe([
            '01 oct. – 02 oct.',
            '05 oct. – 09 oct.',
            '12 oct. – 16 oct.',
            '19 oct. – 23 oct.',
            '26 oct. – 30 oct.',
        ]);
    });

    it('counts the business days of each week and of the month, without the holidays', function () {
        $report = bancosMonth('2026-10-16', bancosOctober());

        expect(array_map(fn (MonthWeek $week): int => $week->businessDays, $report->grids[0]->weeks))->toBe([2, 5, 4, 5, 5])
            ->and($report->businessDays)->toBe(21)
            ->and($report->grids[0]->businessDays)->toBe(21)
            ->and($report->month->toDateString())->toBe('2026-10-01');
    });

    it('marks the weekdays outside the month, the holiday and the days that have not arrived', function () {
        $weeks = bancosMonth('2026-10-16', bancosOctober())->grids[0]->weeks;

        expect(bancosStates($weeks[0]))->toBe(['Outside', 'Outside', 'Outside', 'Loaded', 'Loaded'])
            ->and(bancosStates($weeks[2]))->toBe(['Holiday', 'Loaded', 'Loaded', 'Loaded', 'Pending'])
            ->and(bancosStates($weeks[3]))->toBe(['Pending', 'Pending', 'Pending', 'Pending', 'Pending'])
            ->and($weeks[0]->cells[0]->date->toDateString())->toBe('2026-09-28')
            ->and($weeks[2]->cells[0]->date->toDateString())->toBe('2026-10-12')
            ->and($weeks[0]->hasFigures())->toBeTrue()
            ->and($weeks[3]->hasFigures())->toBeFalse();
    });

    it('labels a week with a single weekday in the month by that day', function () {
        // November 2026: Monday the 30th is the last weekday; the week of 26-30 October has none in November.
        $weeks = bancosMonth('2026-11-10', [], '2026-11-30')->grids[0]->weeks;

        expect($weeks[0]->label())->toBe('02 nov. – 06 nov.')
            ->and($weeks[0]->businessDays)->toBe(4)
            ->and(end($weeks)->label())->toBe('30 nov.')
            ->and(end($weeks)->businessDays)->toBe(1);
    });
});

describe('the amounts', function () {
    it('puts each day in its cell and adds the weekend and the holiday to the next business day', function () {
        $weeks = bancosMonth('2026-10-16', bancosOctober())->grids[0]->weeks;

        expect(bancosAmounts($weeks[0]))->toBe([0.0, 0.0, 0.0, 100.1, 200.2])
            ->and(bancosAmounts($weeks[1]))->toBe([22.0, 0.0, 0.1, 0.2, 0.0])
            ->and(bancosAmounts($weeks[2]))->toBe([0.0, 8.0, 0.0, 0.0, 0.0]);
    });

    it('adds each row, each column and the month in cents, so there is no float drift', function () {
        $grid = bancosMonth('2026-10-16', bancosOctober())->grids[0];

        expect(array_map(fn (MonthWeek $week): float => $week->total, $grid->weeks))->toBe([300.3, 22.3, 8.0, 0.0, 0.0])
            ->and($grid->columnTotals)->toBe([22.0, 8.0, 0.1, 100.3, 200.2])
            ->and($grid->total)->toBe(330.6);
    });

    it('leaves a Saturday at the end of the month to the next month, where it opens the first business day', function () {
        $october = bancosMonth('2026-10-16', bancosOctober(), '2026-11-30')->grids[0];
        $november = bancosMonth('2026-11-10', [
            bancosGridCredit('2026-10-30', 70),       // Friday: October's
            bancosGridCredit('2026-10-31', 50),       // Saturday
            bancosGridCredit('2026-11-02', 60),       // the holiday Monday
            bancosGridCredit('2026-11-03', 1),
        ], '2026-11-30')->grids[0];

        expect($october->total)->toBe(330.6)
            ->and(bancosStates($november->weeks[0]))->toBe(['Holiday', 'Loaded', 'Loaded', 'Loaded', 'Loaded'])
            ->and(bancosAmounts($november->weeks[0]))->toBe([0.0, 111.0, 0.0, 0.0, 0.0])
            ->and($november->total)->toBe(111.0);
    });

    it('asks the provider from the day after the last business day of the month before to the last business day of the month', function () {
        $holidays = new ColombianHolidays;

        expect(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), MonthReport::range(bancosGridDay('2026-10-16'), $holidays)))
            ->toBe(['2026-10-01', '2026-10-30'])
            ->and(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), MonthReport::range(bancosGridDay('2026-11-10'), $holidays)))
            ->toBe(['2026-10-31', '2026-11-30']);
    });
});

describe('the companies', function () {
    it('keeps a grid per company in view, in the order of the companies', function () {
        $report = bancosMonth('2026-10-16', bancosOctober(), companies: [CompanyCode::SDC, CompanyCode::SGX]);

        expect(array_map(fn ($grid) => $grid->company, $report->grids))->toBe([CompanyCode::SGX, CompanyCode::SDC])
            ->and($report->grids[0]->weeks[1]->cells[0]->amount)->toBe(22.0)
            ->and($report->grids[1]->weeks[1]->cells[0]->amount)->toBe(40.0)
            ->and($report->grids[1]->total)->toBe(40.0);
    });

    it('leaves out a company that is not in view', function () {
        $report = bancosMonth('2026-10-16', bancosOctober());

        expect($report->grids)->toHaveCount(1)
            ->and($report->grids[0]->company)->toBe(CompanyCode::SGX);
    });

    it('still draws the grid of a month without credits', function () {
        $grid = bancosMonth('2026-10-16', [])->grids[0];

        expect($grid->weeks)->toHaveCount(5)
            ->and($grid->total)->toBe(0.0)
            ->and(bancosStates($grid->weeks[1]))->toBe(['Loaded', 'Loaded', 'Loaded', 'Loaded', 'Loaded'])
            ->and($grid->weeks[1]->cells[0]->state)->toBe(MonthCellState::Loaded);
    });
});
