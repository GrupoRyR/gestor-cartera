<?php

use App\Modules\Asesores\Domain\Chart\LineChart;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, float>  $values  "Y-m-d" => value
 * @return list<array{date: CarbonImmutable, value: float}>
 */
function asesoresSeries(array $values): array
{
    $series = [];

    foreach ($values as $date => $value) {
        $series[] = ['date' => CarbonImmutable::parse($date, 'America/Bogota'), 'value' => $value];
    }

    return $series;
}

it('spreads the points over the real calendar, not by position', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => 10.0, '2026-10-02' => 20.0, '2026-10-11' => 30.0]), width: 400, height: 100, left: 0, right: 0, top: 0, bottom: 0);

    // Ten days in total: day 1 is a tenth of the way, not half way.
    expect(array_map(fn ($point) => round($point->x, 1), $chart->points))->toBe([0.0, 40.0, 400.0]);
});

it('puts the biggest value at the top and zero at the bottom', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => 0.0, '2026-10-02' => 50.0, '2026-10-03' => 100.0]), width: 200, height: 100, left: 0, right: 0, top: 10, bottom: 10);

    expect(array_map(fn ($point) => $point->y, $chart->points))->toBe([90.0, 50.0, 10.0])
        ->and($chart->max)->toBe(100.0);
});

it('clamps negative values to the baseline', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => -500.0, '2026-10-02' => 100.0]), width: 100, height: 100, left: 0, right: 0, top: 0, bottom: 0);

    expect($chart->points[0]->y)->toBe(100.0)
        ->and($chart->points[0]->value)->toBe(-500.0);
});

it('draws a flat line, not a division by zero, when everything is zero', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => 0.0, '2026-10-02' => 0.0]), width: 100, height: 100, left: 0, right: 0, top: 0, bottom: 0);

    expect($chart->points[0]->y)->toBe(100.0)
        ->and($chart->points[1]->y)->toBe(100.0);
});

it('centres a single point', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => 5.0]), width: 100, height: 100, left: 10, right: 10, top: 0, bottom: 0);

    expect($chart->points[0]->x)->toBe(50.0);
});

it('builds the line and the area under it', function () {
    $chart = LineChart::build(asesoresSeries(['2026-10-01' => 0.0, '2026-10-02' => 100.0]), width: 100, height: 100, left: 0, right: 0, top: 0, bottom: 0);

    expect($chart->path())->toBe('M0,100 L100,0')
        ->and($chart->area())->toBe('M0,100 L100,0 L100,100 L0,100 Z');
});

it('has nothing to draw without points', function () {
    $chart = LineChart::build([], width: 100, height: 100);

    expect($chart->points)->toBe([])
        ->and($chart->path())->toBe('')
        ->and($chart->area())->toBe('');
});
