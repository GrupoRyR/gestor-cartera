<?php

use App\Modules\Gerencia\Http\Presenters\Tones;

it('tones the overdue share by the management thresholds', function (float $percent, string $tone) {
    expect(Tones::overdue($percent))->toBe($tone);
})->with([
    [0.0, 'success'],
    [14.9, 'success'],
    [15.0, 'warning'],
    [29.9, 'warning'],
    [30.0, 'danger'],
    [100.0, 'danger'],
]);

it('tones the compliance rate and leaves a missing one neutral', function (?float $percent, string $tone) {
    expect(Tones::compliance($percent))->toBe($tone);
})->with([
    [null, 'neutral'],
    [100.0, 'success'],
    [90.0, 'success'],
    [89.9, 'warning'],
    [70.0, 'warning'],
    [69.9, 'danger'],
    [0.0, 'danger'],
]);
