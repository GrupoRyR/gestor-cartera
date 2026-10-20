<?php

namespace App\Modules\Gerencia\Http\Presenters;

use InvalidArgumentException;

/**
 * The shared maths of the dashboard charts: round axis ticks, their short
 * Spanish labels and the thinning of the labels under the horizontal axis.
 */
final class ChartScale
{
    public const string PERCENT = 'percent';

    public const string MONEY = 'money';

    /**
     * Axis ticks from zero up to a round top that holds $max.
     *
     * @param  float  $max  Highest value drawn.
     * @param  float  $minTop  Lowest top the axis may have, so an all-zero chart still has a scale.
     * @param  int  $count  Wanted number of intervals; the real one can be one less or one more.
     * @return list<float>
     */
    public static function ticks(float $max, float $minTop, int $count = 4): array
    {
        $top = max($max, $minTop, 1e-9);
        $raw = $top / max(1, $count);
        $magnitude = 10 ** floor(log10($raw));
        $step = 10 * $magnitude;

        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            if ($multiple * $magnitude >= $raw - 1e-12) {
                $step = $multiple * $magnitude;
                break;
            }
        }

        $intervals = (int) ceil($top / $step - 1e-9);
        $ticks = [];

        for ($i = 0; $i <= $intervals; $i++) {
            $ticks[] = round($i * $step, 6);
        }

        return $ticks;
    }

    /**
     * A tick or value as short axis text: "12 %" or "$ 12 M" (millions).
     *
     * @param  float  $step  Distance between ticks, which decides how many decimals the text needs.
     */
    public static function label(string $format, float $value, float $step): string
    {
        return match ($format) {
            self::PERCENT => number_format($value, self::decimals($step), ',', '.').' %',
            self::MONEY => '$ '.number_format($value / 1_000_000, self::decimals($step / 1_000_000), ',', '.').' M',
            default => throw new InvalidArgumentException("Unknown chart format [{$format}]; use percent or money."),
        };
    }

    /**
     * Positions of the labels to print under the horizontal axis so they
     * never touch: every n-th one, counted back from the last, which is
     * always printed.
     *
     * @param  float  $slot  Horizontal distance between two neighbouring labels.
     * @param  float  $minSpacing  Least distance two printed labels may have.
     * @return list<int>
     */
    public static function labelIndexes(int $count, float $slot, float $minSpacing): array
    {
        if ($count <= 0) {
            return [];
        }

        $every = $slot <= 0 ? $count : max(1, (int) ceil($minSpacing / $slot));
        $indexes = [];

        for ($i = $count - 1; $i >= 0; $i -= $every) {
            $indexes[] = $i;
        }

        return array_reverse($indexes);
    }

    /**
     * Compact number text for SVG coordinates.
     */
    public static function coordinate(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * Width in chart units that the widest of these axis labels takes.
     *
     * @param  list<string>  $labels
     */
    public static function labelWidth(array $labels): float
    {
        $longest = 0;

        foreach ($labels as $label) {
            $longest = max($longest, mb_strlen($label));
        }

        return $longest * 7.6;
    }

    private static function decimals(float $step): int
    {
        foreach ([0, 1, 2] as $decimals) {
            $scaled = $step * 10 ** $decimals;

            if (abs($scaled - round($scaled)) < 1e-6) {
                return $decimals;
            }
        }

        return 2;
    }
}
