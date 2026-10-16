<?php

namespace App\Modules\Asesores\Domain\Chart;

use Carbon\CarbonImmutable;

/**
 * The geometry of a one-series line chart drawn as SVG: pure arithmetic, so
 * the view only prints it.
 *
 * - The horizontal axis is the calendar: a gap of days stays a gap.
 * - The vertical axis is linear from 0 to the biggest value; a negative value
 *   sits on the baseline (the chart shows debt, not credit).
 */
final readonly class LineChart
{
    /**
     * @param  list<ChartPoint>  $points  Oldest first.
     * @param  float  $max  Biggest value of the series, never below 0.
     * @param  float  $baseline  Y of the zero line.
     * @param  float  $topLine  Y of the biggest value.
     */
    private function __construct(
        public array $points,
        public float $width,
        public float $height,
        public float $max,
        public float $baseline,
        public float $topLine,
    ) {}

    /**
     * @param  list<array{date: CarbonImmutable, value: float}>  $series  Oldest first.
     */
    public static function build(
        array $series,
        float $width = 640,
        float $height = 180,
        float $left = 8,
        float $right = 76,
        float $top = 16,
        float $bottom = 28,
    ): self {
        $baseline = $height - $bottom;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;

        $max = 0.0;

        foreach ($series as $entry) {
            $max = max($max, $entry['value']);
        }

        // A series that is all zeros still needs a scale to draw its flat line.
        $scale = $max > 0 ? $max : 1.0;
        $first = $series[0]['date'] ?? null;
        $span = $first === null ? 0 : $first->diffInSeconds($series[array_key_last($series)]['date']);
        $points = [];

        foreach ($series as $entry) {
            $x = $span > 0
                ? $left + $plotWidth * ($first->diffInSeconds($entry['date']) / $span)
                : $left + $plotWidth / 2;

            $points[] = new ChartPoint(
                x: $x,
                y: $baseline - $plotHeight * (max($entry['value'], 0.0) / $scale),
                date: $entry['date'],
                value: $entry['value'],
            );
        }

        return new self($points, $width, $height, $max, $baseline, $top);
    }

    /**
     * The "d" attribute of the line.
     */
    public function path(): string
    {
        if ($this->points === []) {
            return '';
        }

        $commands = [];

        foreach ($this->points as $index => $point) {
            $commands[] = ($index === 0 ? 'M' : 'L').self::number($point->x).','.self::number($point->y);
        }

        return implode(' ', $commands);
    }

    /**
     * The "d" attribute of the area between the line and the baseline.
     */
    public function area(): string
    {
        if ($this->points === []) {
            return '';
        }

        $last = $this->points[array_key_last($this->points)];

        return $this->path()
            .' L'.self::number($last->x).','.self::number($this->baseline)
            .' L'.self::number($this->points[0]->x).','.self::number($this->baseline)
            .' Z';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
