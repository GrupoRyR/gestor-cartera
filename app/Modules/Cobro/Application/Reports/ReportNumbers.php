<?php

namespace App\Modules\Cobro\Application\Reports;

/**
 * The figures of the collection report that need more than a count: the
 * median and how a duration and a share read on screen (one decimal, a
 * decimal comma and no trailing ",0").
 */
final class ReportNumbers
{
    /** Up to this many hours a duration reads in hours; from here, in days. */
    public const int HOURS_UNTIL_DAYS = 48;

    /**
     * The middle value, or the mean of the two middle ones; null without values.
     *
     * @param  list<int|float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * "3 h" under 48 hours, "2,5 días" from there; null without a duration.
     */
    public static function duration(?float $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $hours = $seconds / 3600;

        return $hours < self::HOURS_UNTIL_DAYS
            ? __('cobro::informe.duracion.horas', ['valor' => self::decimal($hours)])
            : __('cobro::informe.duracion.dias', ['valor' => self::decimal($hours / 24)]);
    }

    /**
     * "33,3 %"; null when $whole is zero.
     */
    public static function percent(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : self::decimal($part * 100 / $whole).' %';
    }

    private static function decimal(float $value): string
    {
        $shown = number_format($value, 1, ',', '.');

        return str_ends_with($shown, ',0') ? substr($shown, 0, -2) : $shown;
    }
}
