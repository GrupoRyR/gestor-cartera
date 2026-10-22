<?php

use App\Modules\Bancos\Domain\Calendar\ColombianHolidays;
use Carbon\CarbonImmutable;

function bancosCalendarDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
}

describe('the holidays of a year', function () {
    it('follows Ley 51 de 1983 for 2026', function () {
        expect((new ColombianHolidays)->forYear(2026))->toBe([
            '2026-01-01', // Año Nuevo
            '2026-01-12', // Reyes Magos (Jan 6, a Tuesday, moved to Monday)
            '2026-03-23', // San José (Mar 19, a Thursday)
            '2026-04-02', // Jueves Santo
            '2026-04-03', // Viernes Santo
            '2026-05-01', // Día del Trabajo
            '2026-05-18', // Ascensión (Easter + 43)
            '2026-06-08', // Corpus Christi (Easter + 64)
            '2026-06-15', // Sagrado Corazón (Easter + 71)
            '2026-06-29', // San Pedro y San Pablo (already a Monday)
            '2026-07-20', // Independencia
            '2026-08-07', // Batalla de Boyacá
            '2026-08-17', // Asunción (Aug 15, a Saturday)
            '2026-10-12', // Día de la Raza (already a Monday)
            '2026-11-02', // Todos los Santos (Nov 1, a Sunday)
            '2026-11-16', // Independencia de Cartagena (Nov 11, a Wednesday)
            '2026-12-08', // Inmaculada Concepción
            '2026-12-25', // Navidad
        ]);
    });

    it('keeps a movable holiday that already falls on a Monday where it is', function () {
        // 2025-01-06 is a Monday; 2025-11-01 is a Saturday.
        $holidays = (new ColombianHolidays)->forYear(2025);

        expect($holidays)->toContain('2025-01-06')
            ->and($holidays)->not->toContain('2025-01-13')
            ->and($holidays)->toContain('2025-11-03')
            ->and($holidays)->not->toContain('2025-11-01');
    });

    it('does not move the fixed holidays, even on a weekend', function () {
        // 2027-12-25 is a Saturday and 2027-08-07 too.
        expect((new ColombianHolidays)->forYear(2027))->toContain('2027-12-25', '2027-08-07');
    });

    it('computes Easter Sunday without the calendar extension', function (int $year, string $easter) {
        expect(ColombianHolidays::easter($year)->toDateString())->toBe($easter);
    })->with([
        [2024, '2024-03-31'],
        [2025, '2025-04-20'],
        [2026, '2026-04-05'],
        [2027, '2027-03-28'],
        [2038, '2038-04-25'],
    ]);
});

describe('business days', function () {
    it('is Monday to Friday and not a holiday', function (string $date, bool $business) {
        expect((new ColombianHolidays)->isBusinessDay(bancosCalendarDay($date)))->toBe($business);
    })->with([
        'a Friday' => ['2026-10-16', true],
        'a Saturday' => ['2026-10-17', false],
        'a Sunday' => ['2026-10-18', false],
        'a holiday Monday' => ['2026-10-12', false],
        'Holy Thursday' => ['2026-04-02', false],
        'the Tuesday after a holiday' => ['2026-10-13', true],
    ]);

    it('tells a holiday from a plain weekday', function () {
        $holidays = new ColombianHolidays;

        expect($holidays->isHoliday(bancosCalendarDay('2026-10-12')))->toBeTrue()
            ->and($holidays->isHoliday(bancosCalendarDay('2026-10-13')))->toBeFalse();
    });

    it('finds the next business day, skipping weekends and holidays', function (string $from, string $next) {
        expect((new ColombianHolidays)->nextBusinessDay(bancosCalendarDay($from))->toDateString())->toBe($next);
    })->with([
        'from a Friday to Monday' => ['2026-10-02', '2026-10-05'],
        'from a Friday over a holiday Monday' => ['2026-10-09', '2026-10-13'],
        'from a Saturday across the month and a holiday' => ['2026-10-31', '2026-11-03'],
        'from a Tuesday to Wednesday' => ['2026-10-13', '2026-10-14'],
        'from Wednesday over Holy Thursday and Good Friday' => ['2026-04-01', '2026-04-06'],
    ]);

    it('finds the previous business day, skipping weekends and holidays', function (string $from, string $previous) {
        expect((new ColombianHolidays)->previousBusinessDay(bancosCalendarDay($from))->toDateString())->toBe($previous);
    })->with([
        'from a Monday to Friday' => ['2026-10-05', '2026-10-02'],
        'from a Tuesday over a holiday Monday' => ['2026-10-13', '2026-10-09'],
        'from the first business day of November' => ['2026-11-03', '2026-10-30'],
        'across the year' => ['2026-01-02', '2025-12-31'],
    ]);

    it('keeps the time zone of the day it is given', function () {
        expect((new ColombianHolidays)->nextBusinessDay(bancosCalendarDay('2026-10-09'))->getTimezone()->getName())->toBe('America/Bogota');
    });
});
