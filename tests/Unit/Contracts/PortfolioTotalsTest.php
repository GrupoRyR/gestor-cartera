<?php

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use Carbon\CarbonImmutable;

it('adds every bucket into the total and all but not due into overdue', function () {
    $aging = new AgingBuckets(
        notDue: 1_000_000.0,
        days1To30: 200_000.0,
        days31To60: 30_000.0,
        days61To90: 4_000.0,
        over90: 500.0,
    );

    expect($aging->total())->toBe(1_234_500.0)
        ->and($aging->overdue())->toBe(234_500.0);
});

it('starts every bucket at zero', function () {
    $aging = new AgingBuckets;

    expect($aging->total())->toBe(0.0)
        ->and($aging->overdue())->toBe(0.0);
});

it('computes the overdue percentage of the portfolio', function () {
    $totals = new PortfolioTotals(
        cutoffDate: CarbonImmutable::parse('2026-10-14'),
        total: 8_000_000.0,
        overdue: 2_000_000.0,
        clientCount: 10,
        overdueClientCount: 3,
        aging: new AgingBuckets(notDue: 6_000_000.0, days1To30: 2_000_000.0),
    );

    expect($totals->overduePercent())->toBe(25.0);
});

it('reports zero percent overdue for an empty portfolio', function () {
    $totals = new PortfolioTotals(
        cutoffDate: null,
        total: 0.0,
        overdue: 0.0,
        clientCount: 0,
        overdueClientCount: 0,
        aging: new AgingBuckets,
    );

    expect($totals->overduePercent())->toBe(0.0);
});

it('builds empty totals with no snapshot', function () {
    $totals = PortfolioTotals::empty();

    expect($totals->cutoffDate)->toBeNull()
        ->and($totals->total)->toBe(0.0)
        ->and($totals->overdue)->toBe(0.0)
        ->and($totals->clientCount)->toBe(0)
        ->and($totals->overdueClientCount)->toBe(0)
        ->and($totals->aging)->toEqual(new AgingBuckets)
        ->and($totals->overduePercent())->toBe(0.0);
});
