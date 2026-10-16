<?php

use App\Modules\Asesores\Domain\Chart\BarWidth;

it('sizes a bar against the biggest one', function (float $value, float $biggest, float $width) {
    expect(BarWidth::of($value, $biggest))->toBe($width);
})->with([
    'the biggest' => [200.0, 200.0, 100.0],
    'half' => [100.0, 200.0, 50.0],
    'a quarter' => [50.0, 200.0, 25.0],
    'a negative amount is as long as its size' => [-100.0, 200.0, 50.0],
    'a tiny amount stays visible' => [1.0, 1_000_000.0, 1.5],
    'nothing' => [0.0, 200.0, 0.0],
    'nothing to compare with' => [10.0, 0.0, 0.0],
]);
