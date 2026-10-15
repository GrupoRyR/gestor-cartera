<?php

use App\Modules\Core\Application\Portfolio\Cents;

it('reads decimal strings as exact cents', function (int|float|string|null $value, int $cents) {
    expect(Cents::fromDecimal($value))->toBe($cents);
})->with([
    'pesos and cents' => ['1234567.89', 123456789],
    'large amount' => ['9876543210.54', 987654321054],
    'negative' => ['-0.07', -7],
    'no decimals' => ['12', 1200],
    'one decimal' => ['0.5', 50],
    'explicit plus sign' => ['+3.10', 310],
    'surrounding spaces' => [' 1.00 ', 100],
    'rounds a third decimal half up' => ['1.005', 101],
    'rounds a negative third decimal away from zero' => ['-1.005', -101],
    'integer' => [3, 300],
    'float' => [0.1, 10],
    'null' => [null, 0],
]);

it('refuses values that are not decimal numbers', function (string $value) {
    expect(fn () => Cents::fromDecimal($value))->toThrow(InvalidArgumentException::class);
})->with(['thousands separator' => ['1,234.56'], 'empty' => [''], 'text' => ['abc'], 'only a dot' => ['.']]);

it('writes cents as a decimal string with two decimals', function (int $cents, string $decimal) {
    expect(Cents::toDecimal($cents))->toBe($decimal);
})->with([
    [123456789, '1234567.89'],
    [-7, '-0.07'],
    [0, '0.00'],
    [-123400, '-1234.00'],
]);
