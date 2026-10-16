<?php

use App\Modules\Asesores\Domain\Portfolio\AgingBars;
use App\Modules\Core\Contracts\Data\AgingBuckets;

it('gives one row per age bucket, oldest last', function () {
    $rows = AgingBars::rows(new AgingBuckets(notDue: 100, days1To30: 50, days31To60: 25, days61To90: 10, over90: 5));

    expect(array_map(fn ($row) => $row->key, $rows))->toBe(['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'])
        ->and(array_map(fn ($row) => $row->value, $rows))->toBe([100.0, 50.0, 25.0, 10.0, 5.0]);
});

it('sizes each bar against the biggest one', function () {
    $rows = AgingBars::rows(new AgingBuckets(notDue: 200, days1To30: 100, days31To60: 50));

    expect(array_map(fn ($row) => $row->width, $rows))->toBe([100.0, 50.0, 25.0, 0.0, 0.0]);
});

it('shows each bucket as a share of the total', function () {
    $rows = AgingBars::rows(new AgingBuckets(notDue: 750, days1To30: 250));

    expect($rows[0]->share)->toBe(75.0)
        ->and($rows[1]->share)->toBe(25.0)
        ->and($rows[2]->share)->toBe(0.0);
});

it('keeps a tiny bucket visible', function () {
    $rows = AgingBars::rows(new AgingBuckets(notDue: 1_000_000, over90: 1));

    expect($rows[4]->width)->toBe(1.5);
});

it('draws a negative bucket (credit notes, advances) with its own flag', function () {
    $rows = AgingBars::rows(new AgingBuckets(notDue: 100, days1To30: -50));

    expect($rows[1]->negative)->toBeTrue()
        ->and($rows[1]->width)->toBe(50.0)
        ->and($rows[0]->negative)->toBeFalse();
});

it('survives an empty portfolio', function () {
    $rows = AgingBars::rows(new AgingBuckets);

    expect(array_map(fn ($row) => [$row->width, $row->share], $rows))->each->toBe([0.0, 0.0]);
});
