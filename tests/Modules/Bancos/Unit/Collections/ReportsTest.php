<?php

use App\Modules\Bancos\Domain\Collections\DayReport;
use App\Modules\Bancos\Domain\Collections\WeekReport;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

function bancosReportDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
}

/**
 * @param  array<string, float>  $byBank
 */
function bancosCollected(string $date, float $total, array $byBank, CompanyCode $company = CompanyCode::SGX, int $movements = 1): DailyCollection
{
    return new DailyCollection(bancosReportDay($date), $company, $total, $byBank, $movements);
}

describe('the daily report', function () {
    it('has a row per bank, with what each brought and its share of the day', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 1000, ['bogota' => 750, 'bancolombia' => 250], movements: 4),
        ], [CompanyCode::SGX]);

        expect($report->total)->toBe(1000.0)
            ->and($report->movementCount)->toBe(4)
            ->and($report->amount(Bank::Bogota, CompanyCode::SGX))->toBe(750.0)
            ->and($report->bankTotal(Bank::Bancolombia))->toBe(250.0)
            ->and($report->bankShare(Bank::Bogota))->toBe(75.0)
            ->and($report->bankTotal(Bank::Miami))->toBe(0.0)
            ->and($report->bankShare(Bank::Miami))->toBe(0.0)
            ->and($report->isEmpty())->toBeFalse();
    });

    it('shows both companies side by side in the group view', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 600, ['bogota' => 600], CompanyCode::SDC),
            bancosCollected('2026-10-15', 400, ['bogota' => 100, 'miami' => 300], CompanyCode::SGX),
        ], [CompanyCode::SDC, CompanyCode::SGX]);

        expect($report->companies)->toBe([CompanyCode::SGX, CompanyCode::SDC])
            ->and($report->companyTotals)->toBe(['SGX' => 400.0, 'SDC' => 600.0])
            ->and($report->total)->toBe(1000.0)
            ->and($report->bankTotal(Bank::Bogota))->toBe(700.0)
            ->and($report->amount(Bank::Bogota, CompanyCode::SDC))->toBe(600.0);
    });

    it('leaves out a company that is not in view and a day that is not the one asked for', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 100, ['bogota' => 100], CompanyCode::SGX),
            bancosCollected('2026-10-15', 999, ['bogota' => 999], CompanyCode::SDC),
            bancosCollected('2026-10-14', 888, ['bogota' => 888], CompanyCode::SGX),
        ], [CompanyCode::SGX]);

        expect($report->total)->toBe(100.0)
            ->and($report->companies)->toBe([CompanyCode::SGX]);
    });

    it('is empty when nothing came in', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [], [CompanyCode::SGX]);

        expect($report->isEmpty())->toBeTrue()
            ->and($report->total)->toBe(0.0)
            ->and($report->bankShare(Bank::Bogota))->toBe(0.0);
    });
});

describe('the weekly report', function () {
    // Monday 2026-10-12 to Sunday 2026-10-18; the week before starts on 2026-10-05.
    function bancosWeek(array $thisWeek, array $previousWeek = [], string $lastLoaded = '2026-10-18', string $day = '2026-10-14', array $companies = [CompanyCode::SGX]): WeekReport
    {
        return WeekReport::from(bancosReportDay($day), $thisWeek, $previousWeek, $companies, bancosReportDay($lastLoaded));
    }

    it('lists the seven days from Monday with the running total', function () {
        $week = bancosWeek([
            bancosCollected('2026-10-12', 100, ['bogota' => 100]),
            bancosCollected('2026-10-14', 50, ['bogota' => 50]),
        ]);

        expect($week->start->toDateString())->toBe('2026-10-12')
            ->and($week->end->toDateString())->toBe('2026-10-18')
            ->and(array_map(fn ($day) => $day->date->toDateString(), $week->days))->toBe([
                '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18',
            ])
            ->and(array_map(fn ($day) => $day->total, $week->days))->toBe([100.0, 0.0, 50.0, 0.0, 0.0, 0.0, 0.0])
            ->and(array_map(fn ($day) => $day->cumulative, $week->days))->toBe([100.0, 100.0, 150.0, 150.0, 150.0, 150.0, 150.0])
            ->and($week->total)->toBe(150.0);
    });

    it('does not turn the days that have not arrived into zero', function () {
        $week = bancosWeek([bancosCollected('2026-10-12', 100, ['bogota' => 100])], lastLoaded: '2026-10-14');

        expect(array_map(fn ($day) => $day->loaded, $week->days))->toBe([true, true, true, false, false, false, false])
            ->and(array_map(fn ($day) => $day->cumulative, $week->days))->toBe([100.0, 100.0, 100.0, null, null, null, null])
            ->and($week->daysCounted)->toBe(3);
    });

    it('sizes the bars against the biggest day, and a day with money always shows', function () {
        $week = bancosWeek([
            bancosCollected('2026-10-12', 1000, ['bogota' => 1000]),
            bancosCollected('2026-10-13', 500, ['bogota' => 500]),
            bancosCollected('2026-10-14', 1, ['bogota' => 1]),
        ]);

        expect($week->peak())->toBe(1000.0)
            ->and($week->barWidth($week->days[0]))->toBe(100.0)
            ->and($week->barWidth($week->days[1]))->toBe(50.0)
            ->and($week->barWidth($week->days[2]))->toBe(2.0)
            ->and($week->barWidth($week->days[3]))->toBe(0.0);
    });

    it('compares with the week before over the same weekdays, not the whole week', function () {
        // Three days loaded this week (Mon to Wed): 300 pesos. Last week Mon to Wed was 200; Thursday's 900 does not count.
        $week = bancosWeek(
            [bancosCollected('2026-10-12', 300, ['bogota' => 300])],
            [
                bancosCollected('2026-10-05', 100, ['bogota' => 100]),
                bancosCollected('2026-10-07', 100, ['bogota' => 100]),
                bancosCollected('2026-10-08', 900, ['bogota' => 900]),
            ],
            lastLoaded: '2026-10-14',
        );

        expect($week->previousTotal)->toBe(200.0)
            ->and($week->trend())->toBe('up')
            ->and($week->change())->toBe(50.0);
    });

    it('says down, flat or nothing', function () {
        $down = bancosWeek([bancosCollected('2026-10-12', 100, ['bogota' => 100])], [bancosCollected('2026-10-05', 200, ['bogota' => 200])]);
        $flat = bancosWeek([bancosCollected('2026-10-12', 100, ['bogota' => 100])], [bancosCollected('2026-10-05', 100, ['bogota' => 100])]);
        $none = bancosWeek([], []);
        $notStarted = bancosWeek([], [bancosCollected('2026-10-05', 100, ['bogota' => 100])], lastLoaded: '2026-10-11');

        expect($down->trend())->toBe('down')
            ->and($down->change())->toBe(-50.0)
            ->and($flat->trend())->toBe('flat')
            ->and($flat->change())->toBe(0.0)
            ->and($none->trend())->toBeNull()
            ->and($notStarted->trend())->toBeNull()
            ->and($notStarted->change())->toBeNull();
    });

    it('gives no percentage when the week before had nothing', function () {
        $week = bancosWeek([bancosCollected('2026-10-12', 100, ['bogota' => 100])], []);

        expect($week->change())->toBeNull()
            ->and($week->trend())->toBe('up');
    });

    it('adds the companies in view and tells each total', function () {
        $week = bancosWeek([
            bancosCollected('2026-10-12', 100, ['bogota' => 100], CompanyCode::SGX),
            bancosCollected('2026-10-12', 40, ['bogota' => 40], CompanyCode::SDC),
            bancosCollected('2026-10-13', 5, ['bogota' => 5], CompanyCode::SDC),
        ], companies: [CompanyCode::SGX, CompanyCode::SDC]);

        expect($week->total)->toBe(145.0)
            ->and($week->companyTotals)->toBe(['SGX' => 100.0, 'SDC' => 45.0])
            ->and($week->days[0]->total)->toBe(140.0);
    });

    it('leaves out the company that is not in view', function () {
        $week = bancosWeek([
            bancosCollected('2026-10-12', 100, ['bogota' => 100], CompanyCode::SGX),
            bancosCollected('2026-10-12', 40, ['bogota' => 40], CompanyCode::SDC),
        ]);

        expect($week->total)->toBe(100.0);
    });

    it('opens on the Monday of any day of the week', function (string $day) {
        expect(bancosWeek([], day: $day)->start->toDateString())->toBe('2026-10-12');
    })->with(['2026-10-12', '2026-10-15', '2026-10-18']);
});
