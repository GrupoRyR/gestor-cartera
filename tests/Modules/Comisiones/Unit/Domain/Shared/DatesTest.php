<?php

use App\Modules\Comisiones\Domain\Shared\Dates;

describe('day', function () {
    it('normalizes to midnight UTC', function (DateTimeImmutable|string $date, string $expected) {
        expect(Dates::day($date)->format('Y-m-d H:i:s e'))->toBe($expected);
    })->with([
        'string' => ['2026-08-05', '2026-08-05 00:00:00 UTC'],
        'date with time' => [fn () => new DateTimeImmutable('2026-08-05 17:45:10'), '2026-08-05 00:00:00 UTC'],
        'date in another timezone' => [
            fn () => new DateTimeImmutable('2026-08-05 23:30:00', new DateTimeZone('America/Bogota')),
            '2026-08-05 00:00:00 UTC',
        ],
    ]);

    it('rejects strings that are not real Y-m-d dates', function (string $date) {
        Dates::day($date);
    })->throws(InvalidArgumentException::class)->with([
        'empty' => [''],
        'day first' => ['05/08/2026'],
        'impossible day' => ['2026-02-30'],
        'with time' => ['2026-08-05 10:00'],
        'relative' => ['tomorrow'],
    ]);
});

describe('daysBetween', function () {
    it('counts calendar days', function (string $from, string $to, int $expected) {
        expect(Dates::daysBetween(Dates::day($from), Dates::day($to)))->toBe($expected);
    })->with([
        'invoice 8225' => ['2026-04-28', '2026-08-05', 99],
        'invoice 9098' => ['2026-06-30', '2026-08-11', 42],
        'crossing of code 41' => ['2026-08-19', '2026-09-16', 28],
        'same day' => ['2026-08-05', '2026-08-05', 0],
        'leap day' => ['2028-02-28', '2028-03-01', 2],
        'reversed' => ['2026-08-05', '2026-04-28', -99],
    ]);

    it('ignores the time of day', function () {
        $from = new DateTimeImmutable('2026-06-30 23:59:00');
        $to = new DateTimeImmutable('2026-08-11 00:01:00');

        expect(Dates::daysBetween($from, $to))->toBe(42);
    });
});
