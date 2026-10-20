<?php

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Gerencia\Domain\Portfolio\MonthlyTrend;
use App\Modules\Gerencia\Domain\Portfolio\MonthPoint;
use Carbon\CarbonImmutable;

/**
 * @return Closure(?string, float, float): PortfolioTotals
 */
function trendTotalsBuilder(): Closure
{
    return fn (?string $cutoff, float $total = 100.0, float $overdue = 0.0): PortfolioTotals => new PortfolioTotals(
        cutoffDate: $cutoff === null ? null : CarbonImmutable::parse($cutoff),
        total: $total,
        overdue: $overdue,
        clientCount: 1,
        overdueClientCount: 0,
        aging: new AgingBuckets,
    );
}

it('keeps the last cutoff date of each calendar month', function () {
    $totals = trendTotalsBuilder();

    $trend = MonthlyTrend::fromHistory([
        $totals('2026-08-10', 100.0, 0.0),
        $totals('2026-08-28', 140.0, 0.0),
        $totals('2026-09-15', 200.0, 0.0),
        $totals('2026-08-20', 120.0, 0.0),
    ]);

    expect($trend)->toHaveCount(2)
        ->and($trend[0]->month->toDateString())->toBe('2026-08-01')
        ->and($trend[0]->cutoffDate->toDateString())->toBe('2026-08-28')
        ->and($trend[0]->total)->toBe(140.0)
        ->and($trend[1]->month->toDateString())->toBe('2026-09-01')
        ->and($trend[1]->total)->toBe(200.0);
});

it('orders the months ascending whatever the input order', function () {
    $totals = trendTotalsBuilder();

    $trend = MonthlyTrend::fromHistory([
        $totals('2026-10-05', 1.0, 0.0),
        $totals('2025-12-31', 1.0, 0.0),
        $totals('2026-03-01', 1.0, 0.0),
    ]);

    expect(array_map(fn (MonthPoint $point): string => $point->month->toDateString(), $trend))
        ->toBe(['2025-12-01', '2026-03-01', '2026-10-01']);
});

it('separates the same month of different years', function () {
    $totals = trendTotalsBuilder();

    expect(MonthlyTrend::fromHistory([$totals('2025-10-31', 1.0, 0.0), $totals('2026-10-01', 1.0, 0.0)]))->toHaveCount(2);
});

it('skips totals without a cutoff date', function () {
    $totals = trendTotalsBuilder();

    $trend = MonthlyTrend::fromHistory([$totals(null, 1.0, 0.0), $totals('2026-09-30', 1.0, 0.0)]);

    expect($trend)->toHaveCount(1)
        ->and($trend[0]->cutoffDate->toDateString())->toBe('2026-09-30');
});

it('is empty without history', function () {
    expect(MonthlyTrend::fromHistory([]))->toBe([]);
});

it('carries the overdue amount and its share of the total', function () {
    $totals = trendTotalsBuilder();

    $trend = MonthlyTrend::fromHistory([$totals('2026-09-30', 400.0, 100.0)]);

    expect($trend[0]->overdue)->toBe(100.0)
        ->and($trend[0]->overduePercent())->toBe(25.0);
});

it('has an overdue share of zero when the total is zero', function () {
    $point = new MonthPoint(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), 0.0, 50.0);

    expect($point->overduePercent())->toBe(0.0);
});
