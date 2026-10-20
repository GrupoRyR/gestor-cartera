<?php

use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;

it('covers the current month from its first day to today', function () {
    $period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-20 15:30:00'));

    expect($period->key)->toBe(PeriodKey::Month)
        ->and($period->from->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and($period->to->toDateTimeString())->toBe('2026-10-20 00:00:00')
        ->and($period->days())->toBe(20);
});

it('covers a single day on the first of the month', function () {
    $period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-01'));

    expect($period->days())->toBe(1)
        ->and($period->from->toDateString())->toBe($period->to->toDateString());
});

it('covers the whole previous month', function (string $today, string $from, string $to, int $days) {
    $period = ReportPeriod::for(PeriodKey::PreviousMonth, CarbonImmutable::parse($today));

    expect($period->from->toDateTimeString())->toBe("{$from} 00:00:00")
        ->and($period->to->toDateTimeString())->toBe("{$to} 00:00:00")
        ->and($period->days())->toBe($days);
})->with([
    'a month of 30 days' => ['2026-10-20', '2026-09-01', '2026-09-30', 30],
    'a month of 31 days' => ['2026-12-05', '2026-11-01', '2026-11-30', 30],
    'across the year' => ['2026-01-15', '2025-12-01', '2025-12-31', 31],
    'a leap february' => ['2028-03-02', '2028-02-01', '2028-02-29', 29],
]);

it('covers the current month and the two before it for the quarter', function (string $today, string $from) {
    $period = ReportPeriod::for(PeriodKey::Quarter, CarbonImmutable::parse($today));

    expect($period->from->toDateString())->toBe($from)
        ->and($period->to->toDateString())->toBe($today);
})->with([
    'mid year' => ['2026-10-20', '2026-08-01'],
    'across the year' => ['2026-02-10', '2025-12-01'],
    'a short month in between' => ['2026-04-30', '2026-02-01'],
]);

it('covers the year from the first of january to today', function () {
    $period = ReportPeriod::for(PeriodKey::Year, CarbonImmutable::parse('2026-10-20'));

    expect($period->from->toDateString())->toBe('2026-01-01')
        ->and($period->to->toDateString())->toBe('2026-10-20')
        ->and($period->days())->toBe(293);
});

it('contains the dates from its first to its last day, whatever the time of day', function () {
    $period = ReportPeriod::for(PeriodKey::PreviousMonth, CarbonImmutable::parse('2026-10-20'));

    expect($period->contains(CarbonImmutable::parse('2026-09-01 00:00:00')))->toBeTrue()
        ->and($period->contains(CarbonImmutable::parse('2026-09-30 23:59:59')))->toBeTrue()
        ->and($period->contains(CarbonImmutable::parse('2026-08-31 23:59:59')))->toBeFalse()
        ->and($period->contains(CarbonImmutable::parse('2026-10-01 00:00:00')))->toBeFalse();
});
