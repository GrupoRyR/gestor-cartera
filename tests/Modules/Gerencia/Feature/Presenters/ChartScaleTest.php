<?php

use App\Modules\Gerencia\Http\Presenters\ChartScale;

// Presenter tests sit in Feature/ because the Gerencia session writes tests only there.

it('rounds the top of the axis up to a round number', function (float $max, float $minTop, array $expected) {
    expect(ChartScale::ticks($max, $minTop))->toBe($expected);
})->with([
    'percent under the minimum' => [0.0, 10.0, [0.0, 2.5, 5.0, 7.5, 10.0]],
    'percent' => [37.0, 10.0, [0.0, 10.0, 20.0, 30.0, 40.0]],
    'full percent' => [100.0, 10.0, [0.0, 25.0, 50.0, 75.0, 100.0]],
    'millions' => [8_400_000.0, 1_000_000.0, [0.0, 2_500_000.0, 5_000_000.0, 7_500_000.0, 10_000_000.0]],
    'hundreds of millions' => [412_000_000.0, 1_000_000.0, [0.0, 200_000_000.0, 400_000_000.0, 600_000_000.0]],
]);

it('always keeps zero as the first tick and the highest value under the last', function (float $max) {
    $ticks = ChartScale::ticks($max, 10.0);

    expect($ticks[0])->toBe(0.0)
        ->and($ticks[array_key_last($ticks)])->toBeGreaterThanOrEqual($max);
})->with([0.0, 0.4, 9.99, 10.0, 10.01, 99.0, 1_234_567.0]);

it('words the ticks in short Spanish formats', function (string $format, float $value, float $step, string $expected) {
    expect(ChartScale::label($format, $value, $step))->toBe($expected);
})->with([
    'whole percent' => ['percent', 12.0, 10.0, '12 %'],
    'percent with a decimal' => ['percent', 2.5, 2.5, '2,5 %'],
    'millions' => ['money', 12_000_000.0, 4_000_000.0, '$ 12 M'],
    'millions with a decimal' => ['money', 2_500_000.0, 2_500_000.0, '$ 2,5 M'],
    'thousands of millions' => ['money', 1_200_000_000.0, 100_000_000.0, '$ 1.200 M'],
    'zero' => ['money', 0.0, 2_500_000.0, '$ 0,0 M'],
]);

it('refuses an unknown format', function () {
    ChartScale::label('euros', 1.0, 1.0);
})->throws(InvalidArgumentException::class);

it('prints every other label counted back from the last one when they would touch', function () {
    expect(ChartScale::labelIndexes(12, 37.4, 46.0))->toBe([1, 3, 5, 7, 9, 11])
        ->and(ChartScale::labelIndexes(5, 80.0, 46.0))->toBe([0, 1, 2, 3, 4])
        ->and(ChartScale::labelIndexes(31, 13.0, 30.0))->toBe([0, 3, 6, 9, 12, 15, 18, 21, 24, 27, 30]);
});

it('prints the only label of a single position', function () {
    expect(ChartScale::labelIndexes(1, 0.0, 46.0))->toBe([0])
        ->and(ChartScale::labelIndexes(0, 10.0, 46.0))->toBe([]);
});

it('writes coordinates without trailing zeros or a negative zero', function () {
    expect(ChartScale::coordinate(42.4))->toBe('42.4')
        ->and(ChartScale::coordinate(210.0))->toBe('210')
        ->and(ChartScale::coordinate(100.0))->toBe('100')
        ->and(ChartScale::coordinate(0.0))->toBe('0')
        ->and(ChartScale::coordinate(-0.04))->toBe('0')
        ->and(ChartScale::coordinate(12.349))->toBe('12.3');
});
