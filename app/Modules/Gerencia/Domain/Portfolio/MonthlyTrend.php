<?php

namespace App\Modules\Gerencia\Domain\Portfolio;

use App\Modules\Core\Contracts\Data\PortfolioTotals;

/**
 * Turns the snapshot history into one point per calendar month.
 */
final class MonthlyTrend
{
    /**
     * Keeps, per calendar month, the snapshot with the latest cutoff date;
     * totals without a cutoff date are skipped.
     *
     * @param  list<PortfolioTotals>  $history  In any order.
     * @return list<MonthPoint> Ascending by month.
     */
    public static function fromHistory(array $history): array
    {
        /** @var array<string, MonthPoint> $points */
        $points = [];

        foreach ($history as $totals) {
            $cutoff = $totals->cutoffDate;

            if ($cutoff === null) {
                continue;
            }

            $key = $cutoff->format('Y-m');

            if (isset($points[$key]) && $points[$key]->cutoffDate >= $cutoff) {
                continue;
            }

            $points[$key] = new MonthPoint(
                month: $cutoff->startOfMonth()->startOfDay(),
                cutoffDate: $cutoff,
                total: $totals->total,
                overdue: $totals->overdue,
            );
        }

        ksort($points);

        return array_values($points);
    }
}
