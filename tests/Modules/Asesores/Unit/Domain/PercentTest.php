<?php

use App\Modules\Asesores\Domain\Format\Percent;

it('writes a percentage the Colombian way', function (float $value, string $text) {
    expect(Percent::format($value))->toBe($text);
})->with([
    'with decimals' => [12.34, "12,3\u{00A0}%"],
    'rounds up' => [12.36, "12,4\u{00A0}%"],
    'whole' => [100.0, "100,0\u{00A0}%"],
    'zero' => [0.0, "0,0\u{00A0}%"],
    'negative' => [-3.25, "-3,3\u{00A0}%"],
    'thousands' => [1234.5, "1.234,5\u{00A0}%"],
]);

it('accepts the number of decimals', function () {
    expect(Percent::format(12.5, 0))->toBe("13\u{00A0}%")
        ->and(Percent::format(12.346, 2))->toBe("12,35\u{00A0}%");
});
