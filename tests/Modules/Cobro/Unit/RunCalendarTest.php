<?php

use App\Modules\Cobro\Domain\Runs\RunCalendar;
use Carbon\CarbonImmutable;

it('finds the next Wednesday at 17:00', function (string $now, string $next) {
    $result = RunCalendar::nextWeekly(CarbonImmutable::parse($now, 'America/Bogota'), 3, '17:00');

    expect($result->format('Y-m-d H:i'))->toBe($next)
        ->and($result->timezoneName)->toBe('America/Bogota');
})->with([
    'Monday' => ['2026-10-19 09:00', '2026-10-21 17:00'],
    'Wednesday before' => ['2026-10-21 16:59', '2026-10-21 17:00'],
    'Wednesday at the time' => ['2026-10-21 17:00', '2026-10-21 17:00'],
    'Wednesday after' => ['2026-10-21 17:01', '2026-10-28 17:00'],
    'Saturday' => ['2026-10-24 12:00', '2026-10-28 17:00'],
]);

it('finds the next day of the month', function (string $now, string $next) {
    expect(RunCalendar::nextMonthly(CarbonImmutable::parse($now, 'America/Bogota'), 1, '09:00')->format('Y-m-d H:i'))->toBe($next);
})->with([
    'before' => ['2026-10-01 08:00', '2026-10-01 09:00'],
    'after' => ['2026-10-01 10:00', '2026-11-01 09:00'],
    'year end' => ['2026-12-15 10:00', '2027-01-01 09:00'],
]);

it('rejects a bad day or time', function (callable $call) {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'day 7' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), 7, '17:00')],
    'day 29' => [fn () => RunCalendar::nextMonthly(CarbonImmutable::now(), 29, '09:00')],
    'time' => [fn () => RunCalendar::nextWeekly(CarbonImmutable::now(), 3, '5 pm')],
]);
