<?php

namespace App\Modules\Gerencia\Http\Presenters;

/**
 * Where everything goes in the bar chart (<x-gerencia::bar-chart>): the
 * component only chooses classes and prints what this returns.
 *
 * @phpstan-type Bar array{label: string, value: float, text: string, title?: string}
 * @phpstan-type Layout array{
 *     width: int,
 *     height: int,
 *     plot: array{left: float, right: float, top: float, bottom: float},
 *     ticks: list<array{y: float, label: string}>,
 *     xLabels: list<array{x: float, text: string}>,
 *     bars: list<array{path: string|null, slotX: float, slotWidth: float, title: string}>
 * }
 */
final class BarChartGeometry
{
    public const int WIDTH = 480;

    public const int HEIGHT = 260;

    private const float TOP = 14.0;

    private const float BOTTOM_MARGIN = 32.0;

    private const float RIGHT_MARGIN = 10.0;

    /** Space kept between two bars, in chart units. */
    private const float GAP = 2.0;

    private const float MAX_BAR = 28.0;

    private const float RADIUS = 3.0;

    /**
     * @param  list<Bar>  $bars
     * @return Layout
     */
    public static function build(array $bars, string $format): array
    {
        $max = 0.0;

        foreach ($bars as $bar) {
            $max = max($max, $bar['value']);
        }

        $ticks = ChartScale::ticks($max, $format === ChartScale::PERCENT ? 10.0 : 1_000_000.0);
        $step = count($ticks) > 1 ? $ticks[1] - $ticks[0] : 1.0;
        $top = $ticks[array_key_last($ticks)];

        $texts = array_map(fn (float $tick): string => ChartScale::label($format, $tick, $step), $ticks);
        $left = max(36.0, ChartScale::labelWidth($texts) + 12.0);
        $right = self::WIDTH - self::RIGHT_MARGIN;
        $bottom = self::HEIGHT - self::BOTTOM_MARGIN;
        $height = $bottom - self::TOP;
        $count = count($bars);
        $slot = $count === 0 ? 0.0 : ($right - $left) / $count;

        $yTicks = [];

        foreach ($ticks as $i => $tick) {
            $yTicks[] = ['y' => $bottom - $tick / $top * $height, 'label' => $texts[$i]];
        }

        $xLabels = [];

        foreach (ChartScale::labelIndexes($count, $slot, 30.0) as $index) {
            $xLabels[] = ['x' => $left + ($index + 0.5) * $slot, 'text' => $bars[$index]['label']];
        }

        $drawn = [];

        foreach ($bars as $index => $bar) {
            $slotX = $left + $index * $slot;
            $width = min(self::MAX_BAR, max(1.0, $slot - self::GAP));
            $barHeight = $bar['value'] <= 0 ? 0.0 : max(1.0, $bar['value'] / $top * $height);

            $drawn[] = [
                'path' => $barHeight === 0.0 ? null : self::path($slotX + ($slot - $width) / 2, $bottom, $width, $barHeight),
                'slotX' => $slotX,
                'slotWidth' => $slot,
                'title' => ($bar['title'] ?? $bar['label']).': '.$bar['text'],
            ];
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plot' => ['left' => $left, 'right' => $right, 'top' => self::TOP, 'bottom' => $bottom],
            'ticks' => $yTicks,
            'xLabels' => $xLabels,
            'bars' => $drawn,
        ];
    }

    /**
     * A bar standing on the baseline with only its top corners rounded.
     */
    private static function path(float $x, float $baseline, float $width, float $height): string
    {
        $r = min(self::RADIUS, $width / 2, $height);
        $c = ChartScale::coordinate(...);

        return 'M'.$c($x).' '.$c($baseline)
            .' V'.$c($baseline - $height + $r)
            .' a'.$c($r).' '.$c($r).' 0 0 1 '.$c($r).' '.$c(-$r)
            .' H'.$c($x + $width - $r)
            .' a'.$c($r).' '.$c($r).' 0 0 1 '.$c($r).' '.$c($r)
            .' V'.$c($baseline).' Z';
    }
}
