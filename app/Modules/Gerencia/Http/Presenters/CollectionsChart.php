<?php

namespace App\Modules\Gerencia\Http\Presenters;

use App\Modules\Gerencia\Application\Collections\CollectionsReport;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The bars of the collections chart: one per day in a short period, one per
 * week in a long one, with the days and weeks without movements at zero so
 * the horizontal axis is a continuous calendar.
 *
 * @phpstan-import-type Bar from BarChartGeometry
 */
final class CollectionsChart
{
    /** Periods longer than this many days are drawn week by week. */
    public const int DAILY_LIMIT = 45;

    /**
     * @return array{bars: list<Bar>, weekly: bool}
     */
    public static function from(CollectionsReport $report, ReportPeriod $period): array
    {
        $weekly = $period->days() > self::DAILY_LIMIT;

        return ['bars' => $weekly ? self::weeks($report, $period) : self::days($report, $period), 'weekly' => $weekly];
    }

    /**
     * @return list<Bar>
     */
    private static function days(CollectionsReport $report, ReportPeriod $period): array
    {
        $totals = [];

        foreach ($report->dayTotals() as $day) {
            $totals[$day->date->toDateString()] = $day->total;
        }

        $bars = [];

        for ($date = $period->from; $date <= $period->to; $date = $date->addDay()) {
            $value = $totals[$date->toDateString()] ?? 0.0;
            $bars[] = [
                'label' => (string) $date->day,
                'title' => DateFormat::date($date),
                'value' => $value,
                'text' => Money::format($value),
            ];
        }

        return $bars;
    }

    /**
     * @return list<Bar>
     */
    private static function weeks(CollectionsReport $report, ReportPeriod $period): array
    {
        $totals = [];

        foreach ($report->weekly as $week) {
            $key = $week->weekStart->toDateString();
            $totals[$key] = ($totals[$key] ?? 0.0) + $week->total;
        }

        $bars = [];

        for ($start = self::monday($period->from); $start <= $period->to; $start = $start->addWeek()) {
            $value = $totals[$start->toDateString()] ?? 0.0;
            $bars[] = [
                'label' => Figures::dayShort($start),
                'title' => __('gerencia::tablero.recaudo.semanal.titulo_barra', [
                    'desde' => DateFormat::date($start),
                    'hasta' => DateFormat::date($start->addDays(6)),
                ]),
                'value' => $value,
                'text' => Money::format($value),
            ];
        }

        return $bars;
    }

    private static function monday(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }
}
