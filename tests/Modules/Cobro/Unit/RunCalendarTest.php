<?php

use App\Modules\Cobro\Domain\Runs\RunCalendar;
use App\Support\Calendar\ColombianHolidays;
use Carbon\CarbonImmutable;

it('finds the next Tuesday or Friday at 17:00', function (string $now, string $next) {
    $result = RunCalendar::nextWeekly(CarbonImmutable::parse($now, 'America/Bogota'), [2, 5], '17:00');

    expect($result->format('Y-m-d H:i'))->toBe($next)
        ->and($result->timezoneName)->toBe('America/Bogota');
})->with([
    'Monday' => ['2026-10-19 09:00', '2026-10-20 17:00'],
    'Tuesday before' => ['2026-10-20 16:59', '2026-10-20 17:00'],
    'Tuesday at the time' => ['2026-10-20 17:00', '2026-10-20 17:00'],
    'Tuesday after' => ['2026-10-20 17:01', '2026-10-23 17:00'],
    'Wednesday' => ['2026-10-21 12:00', '2026-10-23 17:00'],
    'Friday at the time' => ['2026-10-23 17:00', '2026-10-23 17:00'],
    'Friday after' => ['2026-10-23 17:01', '2026-10-27 17:00'],
    'Saturday' => ['2026-10-24 12:00', '2026-10-27 17:00'],
]);

it('reads the days in any order', function () {
    expect(RunCalendar::nextWeekly(CarbonImmutable::parse('2026-10-21 12:00', 'America/Bogota'), [5, 2], '17:00')->format('Y-m-d H:i'))
        ->toBe('2026-10-23 17:00');
});

it('finds the next 5th business day of a month', function (string $now, string $next) {
    $result = RunCalendar::nextBusinessDayOfMonth(CarbonImmutable::parse($now, 'America/Bogota'), 5, '09:00', new ColombianHolidays);

    expect($result->format('Y-m-d H:i'))->toBe($next)
        ->and($result->timezoneName)->toBe('America/Bogota');
})->with([
    'before' => ['2026-12-07 08:00', '2026-12-07 09:00'],
    'at the time' => ['2026-12-07 09:00', '2026-12-07 09:00'],
    'after, across the year' => ['2026-12-07 10:00', '2027-01-08 09:00'],
    'earlier in the month' => ['2026-11-02 12:00', '2026-11-09 09:00'],
]);

it('gives the business day of the current month, even once it passed', function () {
    expect(RunCalendar::businessDayOfMonth(CarbonImmutable::parse('2026-12-20 15:00', 'America/Bogota'), 5, '09:00', new ColombianHolidays)->format('Y-m-d H:i'))
        ->toBe('2026-12-07 09:00');
});

it('rejects a bad day or time', function (callable $call) {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'no days' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), [], '17:00')],
    'day 7' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), [2, 7], '17:00')],
    'day -1' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), [-1], '17:00')],
    'business day 0' => [fn () => RunCalendar::nextBusinessDayOfMonth(CarbonImmutable::now(), 0, '09:00', new ColombianHolidays)],
    'time' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), [2, 5], '5 pm')],
    'business day time' => [fn () => RunCalendar::businessDayOfMonth(CarbonImmutable::now(), 5, '9am', new ColombianHolidays)],
]);
