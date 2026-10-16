<?php

use App\Modules\Ingesta\Domain\Manual\CellConverter;
use App\Modules\Ingesta\Domain\Schema\ColumnType;

it('turns empty cells into null', function (mixed $value, ColumnType $type) {
    expect(CellConverter::convert($value, $type))->toBeNull();
})->with([
    [null, ColumnType::Text],
    ['', ColumnType::Decimal2],
    ["  \u{00A0} ", ColumnType::Date],
    ['   ', ColumnType::Integer],
]);

it('reads text', function (mixed $value, ?string $expected) {
    expect(CellConverter::convert($value, ColumnType::Text))->toBe($expected);
})->with([
    'trimmed' => ['  Ferretería Ñandú  ', 'Ferretería Ñandú'],
    'non-breaking spaces' => ["\u{00A0}Bogotá\u{00A0}", 'Bogotá'],
    'integral float' => [9280.0, '9280'],
    'large integral float' => [900123456.0, '900123456'],
    'fraction' => [12.5, '12.5'],
    'int' => [13050505, '13050505'],
    'date' => [new DateTimeImmutable('2026-10-01 00:00:00'), '2026-10-01'],
]);

it('reads integers', function (mixed $value, mixed $expected) {
    expect(CellConverter::convert($value, ColumnType::Integer))->toBe($expected);
})->with([
    [3, 3],
    [3.0, 3],
    [-2.0, -2],
    [' 12 ', 12],
    'fraction stays for the validator' => [3.5, 3.5],
    'text stays for the validator' => ['tres', 'tres'],
]);

it('reads amounts as decimal strings with two places', function (mixed $value, mixed $expected) {
    expect(CellConverter::convert($value, ColumnType::Decimal2))->toBe($expected);
})->with([
    'int' => [1500000, '1500000.00'],
    'float' => [1000000.5, '1000000.50'],
    'float rounding' => [0.125, '0.13'],
    'negative float' => [-250000.75, '-250000.75'],
    'plain string' => ['1234.5', '1234.50'],
    'colombian' => ['$ 1.234.567,89', '1234567.89'],
    'colombian non-breaking space' => ["$\u{00A0}1.234.567", '1234567.00'],
    'colombian thousands only' => ['1.234', '1234.00'],
    'thousands with dots' => ['12.345.678', '12345678.00'],
    'decimal comma' => ['12,5', '12.50'],
    'decimal comma two places' => ['-1234,56', '-1234.56'],
    'thousands with commas' => ['1,234,567', '1234567.00'],
    'us style' => ['1,234,567.89', '1234567.89'],
    'negative in parentheses' => ['($ 1.500)', '-1500.00'],
    'negative sign after $' => ['$ -1.500,5', '-1500.50'],
    'negative zero' => ['-0', '0.00'],
    'leading zeros' => ['007', '7.00'],
    'too many places stay for the validator' => ['1.234,567', '1234.567'],
    'nonsense stays' => ['abc', 'abc'],
    'two decimal commas stay' => ['1,2.3,4', '1,2.3,4'],
]);

it('reads dates', function (mixed $value, mixed $expected) {
    expect(CellConverter::convert($value, ColumnType::Date))->toBe($expected);
})->with([
    'datetime cell' => [new DateTimeImmutable('2026-10-01 13:45:00'), '2026-10-01'],
    'excel serial' => [46296, '2026-10-01'],
    'excel serial with time' => [46296.75, '2026-10-01'],
    'leap day serial' => [45351.0, '2024-02-29'],
    'iso string' => ['2026-10-01', '2026-10-01'],
    'iso string with time' => ['2026-10-01 00:00:00', '2026-10-01'],
    'day first' => ['1/10/2026', '2026-10-01'],
    'day first padded' => ['31/12/2026', '2026-12-31'],
    'impossible date stays' => ['31/02/2026', '31/02/2026'],
    'other text stays' => ['mañana', 'mañana'],
]);
