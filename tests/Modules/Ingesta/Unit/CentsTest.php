<?php

use App\Modules\Ingesta\Domain\Cents;

it('reads decimal strings as exact cents', function (string $decimal, int $cents) {
    expect(Cents::fromDecimal($decimal))->toBe($cents);
})->with([
    ['0', 0],
    ['12', 1200],
    ['12.5', 1250],
    ['12.05', 1205],
    ['-0.01', -1],
    ['-1234567.89', -123456789],
    ['9999999999999999.99', 999999999999999999],
]);

it('refuses what is not a decimal with at most two places', function (string $decimal) {
    Cents::fromDecimal($decimal);
})->with(['', '1.234', '1,5', ' 1', '+1', '.5', '1.', '1e3', '12345678901234567'])
    ->throws(InvalidArgumentException::class);

it('sums without float drift and writes the decimal back', function () {
    $total = Cents::sum([Cents::fromDecimal('0.10'), Cents::fromDecimal('0.20'), Cents::fromDecimal('-0.05')]);

    expect($total)->toBe(25)
        ->and(Cents::toDecimal($total))->toBe('0.25')
        ->and(Cents::toDecimal(-123456789))->toBe('-1234567.89')
        ->and(Cents::toDecimal(5))->toBe('0.05')
        ->and(Cents::toFloat(170000075))->toBe(1_700_000.75);
});

it('refuses a sum that leaves the 64-bit range', function () {
    Cents::sum([PHP_INT_MAX, 1]);
})->throws(OverflowException::class);

it('tells whether a decimal(18,2) column holds an amount', function () {
    expect(Cents::fitsDecimal(Cents::fromDecimal('9999999999999999.99')))->toBeTrue()
        ->and(Cents::fitsDecimal(Cents::fromDecimal('-9999999999999999.99')))->toBeTrue()
        ->and(Cents::fitsDecimal(Cents::MAX + 1))->toBeFalse()
        ->and(Cents::fitsDecimal(-Cents::MAX - 1))->toBeFalse()
        ->and(Cents::toDecimal(Cents::MAX))->toBe('9999999999999999.99');
});
