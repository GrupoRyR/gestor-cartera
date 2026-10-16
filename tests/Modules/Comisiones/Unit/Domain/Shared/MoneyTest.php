<?php

use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use Brick\Math\BigDecimal;

describe('parsing', function () {
    it('accepts integers, plain decimal strings and big decimals', function (BigDecimal|int|string $value, string $expected) {
        expect(Money::of($value)->amount())->toBe($expected);
    })->with([
        'integer' => [69_915_134, '69915134'],
        'negative integer' => [-150_000, '-150000'],
        'plain string' => ['2100840', '2100840'],
        'decimal string' => ['7878.150', '7878.15'],
        'negative decimal string' => ['-0.50', '-0.5'],
        'big decimal' => [fn () => BigDecimal::of('2280210.00'), '2280210'],
        'zero with decimals' => ['0.00', '0'],
    ]);

    it('rejects strings that are not plain decimals', function (string $value) {
        Money::of($value);
    })->throws(InvalidArgumentException::class)->with([
        'empty' => [''],
        'blank' => ['  '],
        'thousand separators' => ['1.234.567'],
        'decimal comma' => ['1234,56'],
        'currency sign' => ['$ 1234'],
        'exponent' => ['1e5'],
        'fraction' => ['1/3'],
        'leading point' => ['.5'],
        'trailing point' => ['5.'],
    ]);

    it('starts at zero', function () {
        expect(Money::zero()->amount())->toBe('0')
            ->and(Money::zero()->isZero())->toBeTrue();
    });
});

describe('arithmetic', function () {
    it('adds, subtracts and negates exactly', function () {
        $a = Money::of('100.25');
        $b = Money::of('0.75');

        expect($a->plus($b)->amount())->toBe('101')
            ->and($a->minus($b)->amount())->toBe('99.5')
            ->and($a->negated()->amount())->toBe('-100.25');
    });

    it('sums a list of amounts', function () {
        $total = Money::sum([Money::of(8_000_000), Money::of(6_500_000), Money::of('-500000.50')]);

        expect($total->amount())->toBe('13999999.5')
            ->and(Money::sum([])->amount())->toBe('0');
    });

    it('multiplies by a percentage without rounding', function () {
        $commission = Money::of(2_100_840)->times(Percentage::of('0.75'))->times(Percentage::of(50));

        expect($commission->amount())->toBe('7878.15');
    });

    it('multiplies by a plain factor without rounding', function () {
        expect(Money::of('100.10')->multipliedBy('1.5')->amount())->toBe('150.15')
            ->and(Money::of(3)->multipliedBy(4)->amount())->toBe('12')
            ->and(Money::of(10)->multipliedBy(BigDecimal::of('0.001'))->amount())->toBe('0.01');
    });

    it('rejects a factor that is not a plain decimal', function () {
        Money::of(10)->multipliedBy('1,5');
    })->throws(InvalidArgumentException::class);
});

describe('rounding', function () {
    it('rounds to the peso half up', function (string $value, string $expected) {
        expect(Money::of($value)->rounded()->amount())->toBe($expected);
    })->with([
        'sales commission of code 14' => ['524363.505', '524364'],
        'below the half' => ['7878.49', '7878'],
        'exactly the half' => ['7878.5', '7879'],
        'negative half goes away from zero' => ['-7878.5', '-7879'],
        'already whole' => ['79206', '79206'],
    ]);

    it('rounds the code 14 sales commission of Annex 2', function () {
        expect(Money::of(69_915_134)->times(Percentage::of('0.75'))->rounded()->amount())->toBe('524364');
    });

    it('rounds to cents half up', function (string $value, string $expected) {
        expect(Money::of($value)->toCents()->amount())->toBe($expected);
    })->with([
        'crossing base' => ['2280210.084', '2280210.08'],
        'half a cent' => ['10.005', '10.01'],
        'whole pesos' => ['15', '15'],
    ]);
});

describe('comparison', function () {
    it('compares amounts regardless of scale', function () {
        expect(Money::of('10.50')->equals(Money::of('10.5')))->toBeTrue()
            ->and(Money::of(10)->compareTo(Money::of(11)))->toBe(-1)
            ->and(Money::of(11)->compareTo(Money::of(10)))->toBe(1)
            ->and(Money::of(10)->compareTo(Money::of('10.00')))->toBe(0);
    });

    it('knows its sign', function () {
        expect(Money::of(-1)->isNegative())->toBeTrue()
            ->and(Money::of(-1)->isPositive())->toBeFalse()
            ->and(Money::of(1)->isPositive())->toBeTrue()
            ->and(Money::of('0.00')->isZero())->toBeTrue()
            ->and(Money::of('0.00')->isNegative())->toBeFalse();
    });

    it('exposes the exact decimal', function () {
        expect(Money::of('7878.15')->decimal()->isEqualTo('7878.15'))->toBeTrue();
    });
});
