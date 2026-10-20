<?php

namespace App\Modules\Gerencia\Http\Presenters;

use Carbon\CarbonImmutable;

/**
 * Number and month text of the dashboard, in Colombian format.
 */
final class Figures
{
    /**
     * "23,5 %".
     */
    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.').' %';
    }

    /**
     * "1.250": a whole number with thousands separators.
     */
    public static function count(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /**
     * "1,2": a figure with one decimal.
     */
    public static function decimal(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    /**
     * "oct 26": the month under a chart axis.
     */
    public static function monthShort(CarbonImmutable $month): string
    {
        return self::months('meses_cortos')[$month->month - 1].' '.$month->format('y');
    }

    /**
     * "Octubre 2026": the month in a hover text or a table.
     */
    public static function monthLong(CarbonImmutable $month): string
    {
        return self::months('meses_largos')[$month->month - 1].' '.$month->year;
    }

    /**
     * "5 oct": a day under a chart axis.
     */
    public static function dayShort(CarbonImmutable $day): string
    {
        return $day->day.' '.self::months('meses_cortos')[$day->month - 1];
    }

    /**
     * @return list<string>
     */
    private static function months(string $key): array
    {
        /** @var list<string> $months */
        $months = trans('gerencia::tablero.'.$key);

        return $months;
    }
}
