<?php

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\Validity;

describe('creation', function () {
    it('normalizes both dates to midnight UTC', function () {
        $validity = Validity::from(new DateTimeImmutable('2026-01-01 15:00'), '2026-06-30');

        expect($validity->from->format('Y-m-d H:i e'))->toBe('2026-01-01 00:00 UTC')
            ->and($validity->until?->format('Y-m-d H:i e'))->toBe('2026-06-30 00:00 UTC');
    });

    it('is open-ended without an end date', function () {
        expect(Validity::from('2026-01-01')->until)->toBeNull();
    });

    it('accepts a single day', function () {
        expect(Validity::from('2026-07-15', '2026-07-15')->contains(new DateTimeImmutable('2026-07-15')))->toBeTrue();
    });

    it('rejects an end before the start', function () {
        Validity::from('2026-07-01', '2026-06-30');
    })->throws(InvalidArgumentException::class);
});

describe('contains', function () {
    it('includes both ends', function (string $date, bool $expected) {
        expect(Validity::from('2026-01-01', '2026-06-30')->contains(new DateTimeImmutable($date)))->toBe($expected);
    })->with([
        'first day' => ['2026-01-01', true],
        'last day' => ['2026-06-30', true],
        'day before' => ['2025-12-31', false],
        'day after' => ['2026-07-01', false],
    ]);

    it('has no end when open-ended', function () {
        expect(Validity::from('2026-01-01')->contains(new DateTimeImmutable('2099-12-31')))->toBeTrue();
    });
});

describe('overlaps a period', function () {
    it('overlaps when any day of the month is in force', function (string $from, ?string $until, bool $expected) {
        expect(Validity::from($from, $until)->overlaps(Period::of(2026, 7)))->toBe($expected);
    })->with([
        'whole year' => ['2026-01-01', '2026-12-31', true],
        'open-ended from before' => ['2026-01-01', null, true],
        'starts mid-month' => ['2026-07-16', null, true],
        'ends mid-month' => ['2026-01-01', '2026-07-15', true],
        'starts on the last day' => ['2026-07-31', null, true],
        'ends on the first day' => ['2026-01-01', '2026-07-01', true],
        'ended the month before' => ['2026-01-01', '2026-06-30', false],
        'starts the month after' => ['2026-08-01', null, false],
    ]);
});

describe('overlaps another validity', function () {
    it('detects shared days', function (array $a, array $b, bool $expected) {
        $first = Validity::from(...$a);
        $second = Validity::from(...$b);

        expect($first->overlapsValidity($second))->toBe($expected)
            ->and($second->overlapsValidity($first))->toBe($expected);
    })->with([
        'consecutive' => [['2026-01-01', '2026-06-30'], ['2026-07-01', null], false],
        'shared last day' => [['2026-01-01', '2026-07-01'], ['2026-07-01', null], true],
        'both open-ended' => [['2026-01-01', null], ['2026-07-01', null], true],
        'nested' => [['2026-01-01', '2026-12-31'], ['2026-03-01', '2026-03-31'], true],
        'far apart' => [['2025-01-01', '2025-12-31'], ['2026-03-01', null], false],
    ]);
});
