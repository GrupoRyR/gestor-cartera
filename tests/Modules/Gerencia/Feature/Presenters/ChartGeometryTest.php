<?php

use App\Modules\Gerencia\Http\Presenters\BarChartGeometry;
use App\Modules\Gerencia\Http\Presenters\LineChartGeometry;

/**
 * A series of percent points; null leaves a gap.
 *
 * @param  list<float|null>  $values
 * @return array{name: string, tone: string, points: list<array{label: string, value: float|null, text: string}>}
 */
function lineSeries(string $name, string $tone, array $values): array
{
    return [
        'name' => $name,
        'tone' => $tone,
        'points' => array_map(
            fn (?float $value, int $i): array => ['label' => "m{$i}", 'value' => $value, 'text' => $value === null ? 'Sin dato' : "{$value} %"],
            $values,
            array_keys($values),
        ),
    ];
}

it('puts the highest tick at the top and zero on the baseline of the line chart', function () {
    $chart = LineChartGeometry::build([lineSeries('Grupo', 'group', [0.0, 40.0, 20.0])], 'percent');

    $plot = $chart['plot'];
    $ys = array_column($chart['lines'][0]['points'], 'y');

    expect($chart['ticks'][0]['label'])->toBe('0 %')
        ->and(end($chart['ticks'])['label'])->toBe('40 %')
        ->and($ys[0])->toBe($plot['bottom'])
        ->and($ys[1])->toBe($plot['top'])
        ->and($ys[2])->toEqualWithDelta(($plot['top'] + $plot['bottom']) / 2, 0.0001);
});

it('spreads the points evenly between the axis and the right margin', function () {
    $chart = LineChartGeometry::build([lineSeries('Grupo', 'group', [10.0, 20.0, 30.0])], 'percent');

    $xs = array_column($chart['lines'][0]['points'], 'x');

    expect($xs[0])->toBe($chart['plot']['left'])
        ->and($xs[2])->toBe($chart['plot']['right'])
        ->and($xs[1])->toEqualWithDelta(($xs[0] + $xs[2]) / 2, 0.0001);
});

it('breaks the line where a value is missing and leaves a lone point as a marker', function () {
    $chart = LineChartGeometry::build([
        lineSeries('Grupo', 'group', [10.0, 20.0, null, 30.0, 40.0, 50.0]),
        lineSeries('SDC', 'sdc', [null, 5.0, null, null, null, null]),
    ], 'percent');

    [$group, $sdc] = $chart['lines'];

    expect($group['paths'])->toHaveCount(2)
        ->and($group['paths'][0])->toStartWith('M')->toContain(' L')
        ->and($group['paths'][1])->toStartWith('M')
        ->and($group['points'])->toHaveCount(5)
        ->and($sdc['paths'])->toBe([])
        ->and($sdc['points'])->toHaveCount(1);
});

it('says series, month and value in the hover text of each point', function () {
    $series = lineSeries('Grupo', 'group', [10.0]);
    $series['points'][0]['title'] = 'Octubre 2026';

    $chart = LineChartGeometry::build([$series], 'percent');

    expect($chart['lines'][0]['points'][0]['title'])->toBe('Octubre 2026 · Grupo: 10 %');
});

it('centers a single position and thins the labels of a long axis', function () {
    $one = LineChartGeometry::build([lineSeries('Grupo', 'group', [10.0])], 'percent');
    $many = LineChartGeometry::build([lineSeries('Grupo', 'group', array_fill(0, 12, 10.0))], 'percent');

    expect($one['lines'][0]['points'][0]['x'])->toBe(($one['plot']['left'] + $one['plot']['right']) / 2)
        ->and($one['xLabels'])->toHaveCount(1)
        ->and(array_column($many['xLabels'], 'text'))->toBe(['m1', 'm3', 'm5', 'm7', 'm9', 'm11']);
});

it('keeps an all-zero chart on a scale', function () {
    $chart = LineChartGeometry::build([lineSeries('Grupo', 'group', [0.0, 0.0])], 'percent');

    expect(array_column($chart['ticks'], 'label'))->toBe(['0,0 %', '2,5 %', '5,0 %', '7,5 %', '10,0 %'])
        ->and($chart['lines'][0]['points'][0]['y'])->toBe($chart['plot']['bottom']);
});

it('leaves room on the left for the widest tick label', function () {
    $percent = LineChartGeometry::build([lineSeries('Grupo', 'group', [10.0])], 'percent');
    $money = BarChartGeometry::build([['label' => '1', 'value' => 412_000_000.0, 'text' => 'x']], 'money');

    expect($money['plot']['left'])->toBeGreaterThan($percent['plot']['left']);
});

it('draws a bar for every positive value and none for a zero', function () {
    $chart = BarChartGeometry::build([
        ['label' => '1', 'value' => 0.0, 'text' => '$ 0'],
        ['label' => '2', 'value' => 50.0, 'text' => '$ 50'],
        ['label' => '3', 'value' => 100.0, 'text' => '$ 100'],
    ], 'money');

    [$zero, $half, $full] = $chart['bars'];

    expect($zero['path'])->toBeNull()
        ->and($half['path'])->toStartWith('M')->toEndWith('Z')
        ->and($full['path'])->toStartWith('M')
        ->and($zero['title'])->toBe('1: $ 0')
        // Every bar owns an equal column.
        ->and($half['slotWidth'])->toBe($full['slotWidth'])
        ->and($full['slotX'])->toEqualWithDelta($half['slotX'] + $half['slotWidth'], 0.0001);
});

it('keeps two units of space between neighbouring bars and a rounded top', function () {
    $chart = BarChartGeometry::build(array_map(
        fn (int $i): array => ['label' => (string) $i, 'value' => 10_000_000.0, 'text' => 'x'],
        range(1, 24),
    ), 'money');

    $path = $chart['bars'][0]['path'];
    preg_match('/^M([\d.]+) ([\d.]+) V[\d.]+ a([\d.]+) ([\d.]+) 0 0 1 [\d.]+ -[\d.]+ H([\d.]+)/', (string) $path, $parts);

    // The path runs from its left edge (M) to the right corner (H + radius).
    $width = (float) $parts[5] - (float) $parts[1] + (float) $parts[3];

    expect($parts)->not->toBe([])
        ->and($width)->toEqualWithDelta($chart['bars'][0]['slotWidth'] - 2.0, 0.2)
        ->and((float) $parts[3])->toBeGreaterThan(0.0);
});

it('labels the days of a month without letting the labels touch', function () {
    $bars = array_map(fn (int $day): array => ['label' => (string) $day, 'value' => 1.0, 'text' => 'x'], range(1, 31));
    $chart = BarChartGeometry::build($bars, 'money');

    $labels = array_column($chart['xLabels'], 'text');

    expect($labels)->toContain('31')
        ->and(count($labels))->toBeLessThan(16);
});

it('has no bars to draw without data', function () {
    $chart = BarChartGeometry::build([], 'money');

    expect($chart['bars'])->toBe([])->and($chart['xLabels'])->toBe([])->and($chart['ticks'])->not->toBe([]);
});
