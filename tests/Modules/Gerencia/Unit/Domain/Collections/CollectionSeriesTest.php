<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Domain\Collections\CollectionSeries;
use App\Modules\Gerencia\Domain\Collections\DayTotal;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

it('adds the companies of each date and orders the dates ascending', function () {
    $series = CollectionSeries::daily([
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-03'), company: CompanyCode::SGX, total: 100.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-01'), company: CompanyCode::SDC, total: 30.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-03'), company: CompanyCode::SDC, total: 50.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-01'), company: CompanyCode::SGX, total: 20.0),
    ]);

    expect(array_map(fn (DayTotal $day): array => [$day->date->toDateString(), $day->total], $series))
        ->toBe([['2026-10-01', 50.0], ['2026-10-03', 150.0]]);
});

it('is empty without collections', function () {
    expect(CollectionSeries::daily([]))->toBe([]);
});
