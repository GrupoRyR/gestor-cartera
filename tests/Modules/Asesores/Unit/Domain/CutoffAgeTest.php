<?php

use App\Modules\Asesores\Domain\Portfolio\CutoffAge;
use Carbon\CarbonImmutable;

function asesoresMoment(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'America/Bogota');
}

it('counts the calendar days between the cutoff and today', function (string $cutoff, string $today, int $days) {
    expect(CutoffAge::days(asesoresMoment($cutoff), asesoresMoment($today)))->toBe($days);
})->with([
    'same day' => ['2026-10-16 07:00', '2026-10-16 23:59', 0],
    'yesterday late' => ['2026-10-15 23:59', '2026-10-16 00:01', 1],
    'a week' => ['2026-10-09', '2026-10-16', 7],
    'a cutoff in the future' => ['2026-10-17', '2026-10-16', -1],
]);

it('is stale only after the allowed number of days', function (string $cutoff, bool $stale) {
    expect(CutoffAge::isStale(asesoresMoment($cutoff), asesoresMoment('2026-10-16 10:00'), 2))->toBe($stale);
})->with([
    'today' => ['2026-10-16', false],
    'two days old' => ['2026-10-14', false],
    'three days old' => ['2026-10-13', true],
]);

it('does not call a missing cutoff stale: there is nothing to show yet', function () {
    expect(CutoffAge::isStale(null, asesoresMoment('2026-10-16'), 2))->toBeFalse();
});
