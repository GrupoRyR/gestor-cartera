<?php

use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Collections\CollectionsReport;
use App\Modules\Gerencia\Application\Portfolio\ScopeFigures;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use App\Modules\Gerencia\Domain\Portfolio\MonthPoint;
use App\Modules\Gerencia\Domain\Portfolio\PortfolioRotation;
use App\Modules\Gerencia\Http\Presenters\CollectionsChart;
use App\Modules\Gerencia\Http\Presenters\TrendChart;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

/**
 * @param  list<array{string, float, float}>  $months  [first day of the month, total, overdue]
 */
function scopeFigures(?CompanyCode $company, string $name, array $months): ScopeFigures
{
    return new ScopeFigures(
        company: $company,
        name: $name,
        totals: PortfolioTotals::empty(),
        trend: array_map(
            fn (array $month): MonthPoint => new MonthPoint(CarbonImmutable::parse($month[0]), CarbonImmutable::parse($month[0])->endOfMonth()->startOfDay(), $month[1], $month[2]),
            $months,
        ),
        rotation: PortfolioRotation::from(0.0, 0.0, 1),
    );
}

it('lines every scope up on the months any of them has', function () {
    $trend = TrendChart::from([
        scopeFigures(null, 'Grupo RYR', [['2026-08-01', 1000.0, 100.0], ['2026-09-01', 1000.0, 200.0]]),
        scopeFigures(CompanyCode::SDC, 'SDC', [['2026-09-01', 200.0, 100.0]]),
    ]);

    expect($trend['series'])->toHaveCount(2)
        ->and($trend['series'][0]['tone'])->toBe('group')
        ->and($trend['series'][1]['tone'])->toBe('sdc')
        ->and(array_column($trend['series'][1]['points'], 'value'))->toBe([null, 50.0])
        ->and(array_column($trend['series'][1]['points'], 'text'))->toBe(['Sin dato', '50,0 %'])
        ->and(array_column($trend['series'][0]['points'], 'label'))->toBe(['ago 26', 'sep 26'])
        ->and(array_column($trend['series'][0]['points'], 'title'))->toBe(['Agosto 2026', 'Septiembre 2026']);
});

it('builds the table rows from the first scope and one percent per scope', function () {
    $trend = TrendChart::from([
        scopeFigures(null, 'Grupo RYR', [['2026-08-01', 1000.0, 100.0], ['2026-09-01', 1000.0, 200.0]]),
        scopeFigures(CompanyCode::SDC, 'SDC', [['2026-09-01', 200.0, 100.0]]),
    ]);

    expect($trend['rows'])->toBe([
        ['month' => 'Agosto 2026', 'total' => 1000.0, 'overdue' => 100.0, 'percents' => [10.0, null]],
        ['month' => 'Septiembre 2026', 'total' => 1000.0, 'overdue' => 200.0, 'percents' => [20.0, 50.0]],
    ]);
});

it('has no series and no rows without scopes', function () {
    expect(TrendChart::from([]))->toBe(['series' => [], 'rows' => []]);
});

it('gives each entity its own tone', function () {
    expect(TrendChart::tone(CompanyCode::SGX))->toBe('sgx')
        ->and(TrendChart::tone(CompanyCode::SDC))->toBe('sdc')
        ->and(TrendChart::tone(null))->toBe('group');
});

it('draws a month day by day, with the days without movements at zero', function () {
    $period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-20'));
    $report = new CollectionsReport([
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 100.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SDC, total: 50.0),
    ], []);

    $chart = CollectionsChart::from($report, $period);

    expect($chart['weekly'])->toBeFalse()
        ->and($chart['bars'])->toHaveCount(20)
        ->and($chart['bars'][0])->toBe(['label' => '1', 'title' => '01/10/2026', 'value' => 0.0, 'text' => Money::format(0)])
        ->and($chart['bars'][9]['value'])->toBe(150.0)
        ->and($chart['bars'][9]['text'])->toBe(Money::format(150));
});

it('draws a long period week by week from the Monday that holds its first day', function () {
    $period = ReportPeriod::for(PeriodKey::Year, CarbonImmutable::parse('2026-10-20'));
    $report = new CollectionsReport([], [
        new WeeklyCollection(CarbonImmutable::parse('2026-01-05'), CarbonImmutable::parse('2026-01-11'), CompanyCode::SGX, 300.0, []),
        new WeeklyCollection(CarbonImmutable::parse('2026-01-05'), CarbonImmutable::parse('2026-01-11'), CompanyCode::SDC, 100.0, []),
    ]);

    $chart = CollectionsChart::from($report, $period);

    // From Monday 29/12/2025 to Monday 19/10/2026.
    expect($chart['weekly'])->toBeTrue()
        ->and($chart['bars'])->toHaveCount(43)
        ->and($chart['bars'][0]['label'])->toBe('29 dic')
        ->and($chart['bars'][1]['label'])->toBe('5 ene')
        ->and($chart['bars'][1]['value'])->toBe(400.0)
        ->and($chart['bars'][1]['title'])->toBe('Semana del 05/01/2026 al 11/01/2026')
        ->and($chart['bars'][42]['label'])->toBe('19 oct');
});

it('switches to weeks above forty-five days', function (PeriodKey $key, bool $weekly) {
    $period = ReportPeriod::for($key, CarbonImmutable::parse('2026-10-20'));

    expect(CollectionsChart::from(new CollectionsReport([], []), $period)['weekly'])->toBe($weekly);
})->with([
    [PeriodKey::Month, false],
    [PeriodKey::PreviousMonth, false],
    [PeriodKey::Quarter, true],
    [PeriodKey::Year, true],
]);
