<?php

use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;

it('formats dates as day, month and year', function () {
    expect(DateFormat::date(CarbonImmutable::parse('2026-10-15 09:30', 'America/Bogota')))->toBe('15/10/2026')
        ->and(DateFormat::date('2026-01-05'))->toBe('05/01/2026');
});

it('formats date and time with a 24-hour clock', function () {
    expect(DateFormat::dateTime(CarbonImmutable::parse('2026-10-15 14:05', 'America/Bogota')))->toBe('15/10/2026 14:05')
        ->and(DateFormat::dateTime('2026-10-15 07:00:00'))->toBe('15/10/2026 07:00');
});

it('converts values to Colombian time before formatting', function () {
    $utc = CarbonImmutable::parse('2026-10-15 03:00', 'UTC');

    expect(DateFormat::date($utc))->toBe('14/10/2026')
        ->and(DateFormat::dateTime($utc))->toBe('14/10/2026 22:00')
        ->and(DateFormat::dateTime('2026-10-15T03:00:00Z'))->toBe('14/10/2026 22:00')
        ->and(DateFormat::dateTime(new DateTime('2026-10-15 12:00', new DateTimeZone('UTC'))))->toBe('15/10/2026 07:00');
});

it('keeps date-only strings on the same calendar day', function () {
    expect(DateFormat::date('2026-12-31'))->toBe('31/12/2026');
});

it('returns the fallback for empty values', function () {
    expect(DateFormat::date(null))->toBe('—')
        ->and(DateFormat::date(''))->toBe('—')
        ->and(DateFormat::dateTime('  '))->toBe('—')
        ->and(DateFormat::date(null, 'Sin fecha'))->toBe('Sin fecha');
});

it('builds the machine-readable value of the datetime attribute', function () {
    $utc = CarbonImmutable::parse('2026-10-15 03:00', 'UTC');

    expect(DateFormat::iso($utc))->toBe('2026-10-14')
        ->and(DateFormat::iso($utc, withTime: true))->toBe('2026-10-14T22:00:00-05:00')
        ->and(DateFormat::iso('2026-10-15'))->toBe('2026-10-15');
});

it('rejects strings that are not dates', function () {
    DateFormat::date('mañana temprano');
})->throws(InvalidArgumentException::class);

it('rejects an empty string for the datetime attribute', function () {
    DateFormat::iso('');
})->throws(InvalidArgumentException::class);
