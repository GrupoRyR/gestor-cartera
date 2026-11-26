<?php

use App\Modules\Cobro\Application\Reports\ReportNumbers;

it('takes the middle value, or the mean of the two middle ones, in any order', function (array $values, ?float $median) {
    expect(ReportNumbers::median($values))->toBe($median);
})->with([
    'none' => [[], null],
    'one' => [[7], 7.0],
    'odd count' => [[9, 2, 4], 4.0],
    'even count' => [[5, 2, 9, 1], 3.5],
    'repeated values' => [[3, 3, 8, 3], 3.0],
]);

it('shows a duration in hours under 48 hours and in days from there', function (?float $seconds, ?string $shown) {
    expect(ReportNumbers::duration($seconds))->toBe($shown);
})->with([
    'none' => [null, null],
    'zero' => [0.0, '0 h'],
    'ten minutes' => [600.0, '0,2 h'],
    'an hour and a half' => [5400.0, '1,5 h'],
    'three hours' => [10800.0, '3 h'],
    'just under two days' => [47.9 * 3600, '47,9 h'],
    'two days' => [48.0 * 3600, '2 días'],
    'two and a half days' => [61.0 * 3600, '2,5 días'],
]);

it('shows a share as a percentage, a dash without a whole', function (int $part, int $whole, ?string $shown) {
    expect(ReportNumbers::percent($part, $whole))->toBe($shown);
})->with([
    'nothing to share' => [0, 0, null],
    'none' => [0, 4, '0 %'],
    'half' => [2, 4, '50 %'],
    'a third' => [1, 3, '33,3 %'],
    'all' => [1200, 1200, '100 %'],
]);
