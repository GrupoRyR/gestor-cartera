<?php

namespace App\Modules\Gerencia\Http\Presenters;

/**
 * Where everything goes in the line chart (<x-gerencia::line-chart>): the
 * component only chooses classes and prints what this returns.
 *
 * Every series lists the same horizontal positions in the same order; a
 * position without a value is null and breaks the line there.
 *
 * @phpstan-type Point array{label: string, value: float|null, text: string, title?: string}
 * @phpstan-type Series array{name: string, tone: string, points: list<Point>}
 * @phpstan-type Line array{name: string, tone: string, paths: list<string>, points: list<array{x: float, y: float, title: string}>}
 * @phpstan-type Layout array{
 *     width: int,
 *     height: int,
 *     plot: array{left: float, right: float, top: float, bottom: float},
 *     ticks: list<array{y: float, label: string}>,
 *     xLabels: list<array{x: float, text: string}>,
 *     lines: list<Line>
 * }
 */
final class LineChartGeometry
{
    public const int WIDTH = 480;

    public const int HEIGHT = 260;

    private const float TOP = 14.0;

    private const float BOTTOM_MARGIN = 32.0;

    private const float RIGHT_MARGIN = 26.0;

    /**
     * @param  list<Series>  $series
     * @return Layout
     */
    public static function build(array $series, string $format): array
    {
        $count = 0;
        $max = 0.0;

        foreach ($series as $line) {
            $count = max($count, count($line['points']));

            foreach ($line['points'] as $point) {
                $max = max($max, $point['value'] ?? 0.0);
            }
        }

        $ticks = ChartScale::ticks($max, $format === ChartScale::PERCENT ? 10.0 : 1_000_000.0);
        $step = count($ticks) > 1 ? $ticks[1] - $ticks[0] : 1.0;
        $top = $ticks[array_key_last($ticks)];

        $texts = array_map(fn (float $tick): string => ChartScale::label($format, $tick, $step), $ticks);
        $left = max(36.0, ChartScale::labelWidth($texts) + 12.0);
        $right = self::WIDTH - self::RIGHT_MARGIN;
        $bottom = self::HEIGHT - self::BOTTOM_MARGIN;
        $slot = $count <= 1 ? 0.0 : ($right - $left) / ($count - 1);

        $x = fn (int $index): float => $count <= 1 ? ($left + $right) / 2 : $left + $index * $slot;
        $y = fn (float $value): float => $bottom - $value / $top * ($bottom - self::TOP);

        $yTicks = [];

        foreach ($ticks as $i => $tick) {
            $yTicks[] = ['y' => $y($tick), 'label' => $texts[$i]];
        }

        $xLabels = [];
        $labelled = $series[0]['points'] ?? [];

        foreach (ChartScale::labelIndexes(count($labelled), $slot, 46.0) as $index) {
            $xLabels[] = ['x' => $x($index), 'text' => $labelled[$index]['label']];
        }

        $lines = [];

        foreach ($series as $line) {
            $lines[] = self::line($line, $x, $y);
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plot' => ['left' => $left, 'right' => $right, 'top' => self::TOP, 'bottom' => $bottom],
            'ticks' => $yTicks,
            'xLabels' => $xLabels,
            'lines' => $lines,
        ];
    }

    /**
     * @param  Series  $line
     * @param  callable(int): float  $x
     * @param  callable(float): float  $y
     * @return Line
     */
    private static function line(array $line, callable $x, callable $y): array
    {
        $paths = [];
        $points = [];
        $run = [];

        foreach ($line['points'] as $index => $point) {
            if ($point['value'] === null) {
                $paths[] = self::path($run);
                $run = [];

                continue;
            }

            $px = $x($index);
            $py = $y($point['value']);
            $run[] = [$px, $py];
            $points[] = [
                'x' => $px,
                'y' => $py,
                'title' => ($point['title'] ?? $point['label']).' · '.$line['name'].': '.$point['text'],
            ];
        }

        $paths[] = self::path($run);

        return [
            'name' => $line['name'],
            'tone' => $line['tone'],
            // A run of one point has no line to draw; its marker shows it.
            'paths' => array_values(array_filter($paths, fn (string $path): bool => $path !== '')),
            'points' => $points,
        ];
    }

    /**
     * @param  list<array{float, float}>  $run
     */
    private static function path(array $run): string
    {
        if (count($run) < 2) {
            return '';
        }

        $commands = [];

        foreach ($run as $i => [$x, $y]) {
            $commands[] = ($i === 0 ? 'M' : 'L').ChartScale::coordinate($x).' '.ChartScale::coordinate($y);
        }

        return implode(' ', $commands);
    }
}
