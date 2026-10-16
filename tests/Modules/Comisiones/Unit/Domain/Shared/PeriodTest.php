<?php

use App\Modules\Comisiones\Domain\Shared\Period;

describe('creation', function () {
    it('builds from year and month or from its key', function () {
        expect(Period::of(2026, 7)->key())->toBe('2026-07')
            ->and(Period::fromString('2026-07')->equals(Period::of(2026, 7)))->toBeTrue()
            ->and(Period::of(2026, 7)->equals(Period::of(2026, 8)))->toBeFalse();
    });

    it('rejects an invalid month or year', function (int $year, int $month) {
        Period::of($year, $month);
    })->throws(InvalidArgumentException::class)->with([
        'month zero' => [2026, 0],
        'month thirteen' => [2026, 13],
        'three digit year' => [999, 1],
    ]);

    it('rejects a malformed key', function (string $key) {
        Period::fromString($key);
    })->throws(InvalidArgumentException::class)->with([
        'empty' => [''],
        'day included' => ['2026-07-01'],
        'single digit month' => ['2026-7'],
        'slash' => ['07/2026'],
        'month thirteen' => ['2026-13'],
    ]);
});

describe('dates', function () {
    it('starts on the first day and ends on the last day at midnight UTC', function (string $key, string $start, string $end) {
        $period = Period::fromString($key);

        expect($period->start()->format('Y-m-d H:i:s e'))->toBe("{$start} 00:00:00 UTC")
            ->and($period->end()->format('Y-m-d H:i:s e'))->toBe("{$end} 00:00:00 UTC");
    })->with([
        'july' => ['2026-07', '2026-07-01', '2026-07-31'],
        'september' => ['2026-09', '2026-09-01', '2026-09-30'],
        'february' => ['2026-02', '2026-02-01', '2026-02-28'],
        'leap february' => ['2028-02', '2028-02-01', '2028-02-29'],
        'december' => ['2026-12', '2026-12-01', '2026-12-31'],
    ]);

    it('contains the days of its month only', function (string $date, bool $expected) {
        expect(Period::of(2028, 2)->contains(new DateTimeImmutable($date)))->toBe($expected);
    })->with([
        'first day' => ['2028-02-01', true],
        'leap day' => ['2028-02-29', true],
        'leap day late in the evening' => ['2028-02-29 23:59:59', true],
        'day before' => ['2028-01-31', false],
        'day after' => ['2028-03-01', false],
    ]);

    it('uses the calendar day of a date in another timezone', function () {
        $lateInBogota = new DateTimeImmutable('2026-07-31 22:00:00', new DateTimeZone('America/Bogota'));

        expect(Period::of(2026, 7)->contains($lateInBogota))->toBeTrue();
    });
});

describe('navigation', function () {
    it('moves to the previous and next month across years', function () {
        expect(Period::of(2026, 1)->previous()->key())->toBe('2025-12')
            ->and(Period::of(2026, 12)->next()->key())->toBe('2027-01')
            ->and(Period::of(2026, 7)->next()->key())->toBe('2026-08')
            ->and(Period::of(2026, 7)->previous()->key())->toBe('2026-06');
    });
});
