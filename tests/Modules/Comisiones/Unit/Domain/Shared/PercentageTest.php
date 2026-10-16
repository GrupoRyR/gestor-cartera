<?php

use App\Modules\Comisiones\Domain\Shared\Percentage;
use Brick\Math\BigDecimal;

describe('creation', function () {
    it('stores the fraction of a percent number', function (int|string $percent, string $fraction, string $shown) {
        $percentage = Percentage::of($percent);

        expect($percentage->fraction()->isEqualTo($fraction))->toBeTrue()
            ->and($percentage->percent())->toBe($shown);
    })->with([
        'base rate' => ['0.75', '0.0075', '0.75'],
        'special rate' => ['1.25', '0.0125', '1.25'],
        'director rate' => ['0.2', '0.002', '0.2'],
        'derived rate' => ['0.1', '0.001', '0.1'],
        'minimum' => [90, '0.9', '90'],
        'full' => [100, '1', '100'],
        'compliance above 100 %' => ['108.2', '1.082', '108.2'],
        'zero' => [0, '0', '0'],
    ]);

    it('builds from a fraction', function (BigDecimal|string $fraction, string $shown) {
        expect(Percentage::fromFraction($fraction)->percent())->toBe($shown);
    })->with([
        'compliance of code 14' => ['0.905146', '90.5146'],
        'big decimal' => [fn () => BigDecimal::of('0.0075'), '0.75'],
        'trailing zeros' => ['0.500000', '50'],
    ]);

    it('has zero and full shortcuts', function () {
        expect(Percentage::zero()->isZero())->toBeTrue()
            ->and(Percentage::full()->percent())->toBe('100')
            ->and(Percentage::full()->fraction()->isEqualTo(1))->toBeTrue();
    });

    it('rejects negative values', function (Closure $build) {
        $build();
    })->throws(InvalidArgumentException::class)->with([
        'negative percent' => [fn () => Percentage::of('-0.75')],
        'negative fraction' => [fn () => Percentage::fromFraction('-0.1')],
    ]);

    it('rejects values that are not plain decimals', function (string $percent) {
        Percentage::of($percent);
    })->throws(InvalidArgumentException::class)->with([
        'empty' => [''],
        'decimal comma' => ['0,75'],
        'percent sign' => ['0.75 %'],
    ]);
});

describe('comparison', function () {
    it('compares percentages regardless of scale', function () {
        expect(Percentage::of('90')->equals(Percentage::fromFraction('0.900')))->toBeTrue()
            ->and(Percentage::of(80)->compareTo(Percentage::of(90)))->toBe(-1)
            ->and(Percentage::of(90)->compareTo(Percentage::of(80)))->toBe(1)
            ->and(Percentage::of('0.75')->compareTo(Percentage::fromFraction('0.0075')))->toBe(0);
    });
});
