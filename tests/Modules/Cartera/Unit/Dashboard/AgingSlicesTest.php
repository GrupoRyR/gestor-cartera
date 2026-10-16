<?php

use App\Modules\Cartera\Domain\Dashboard\AgingBand;
use App\Modules\Cartera\Domain\Dashboard\AgingSlices;
use App\Modules\Core\Contracts\Data\AgingBuckets;

it('keeps the four overdue bands in order and leaves out what is not due', function () {
    $slices = AgingSlices::from(new AgingBuckets(notDue: 900.0, days1To30: 10.0, days31To60: 20.0, days61To90: 30.0, over90: 40.0));

    expect(array_map(fn ($slice) => $slice->band, $slices))->toBe([
        AgingBand::Days1To30,
        AgingBand::Days31To60,
        AgingBand::Days61To90,
        AgingBand::Over90,
    ])->and(array_map(fn ($slice) => $slice->amount, $slices))->toBe([10.0, 20.0, 30.0, 40.0]);
});

it('shares each band of the overdue amount as a percentage', function () {
    $slices = AgingSlices::from(new AgingBuckets(days1To30: 25.0, days31To60: 25.0, days61To90: 0.0, over90: 50.0));

    expect(array_map(fn ($slice) => $slice->share, $slices))->toBe([25.0, 25.0, 0.0, 50.0]);
});

it('gives every band a zero share when nothing is overdue', function () {
    $slices = AgingSlices::from(new AgingBuckets(notDue: 500.0));

    expect($slices)->toHaveCount(4)
        ->and(array_sum(array_map(fn ($slice) => $slice->share, $slices)))->toBe(0.0);
});
