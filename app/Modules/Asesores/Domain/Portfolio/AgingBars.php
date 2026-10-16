<?php

namespace App\Modules\Asesores\Domain\Portfolio;

use App\Modules\Core\Contracts\Data\AgingBuckets;

/**
 * Turns the age buckets of a portfolio into the rows of a bar chart. The
 * buckets are Core's (days overdue to the cutoff, grace days ignored).
 */
final class AgingBars
{
    /**
     * A bucket with money in it never draws thinner than this, in percent.
     */
    private const float MIN_WIDTH = 1.5;

    /**
     * @return list<AgingRow> Not due first, then older and older.
     */
    public static function rows(AgingBuckets $aging): array
    {
        $values = [
            'not_due' => $aging->notDue,
            'days_1_30' => $aging->days1To30,
            'days_31_60' => $aging->days31To60,
            'days_61_90' => $aging->days61To90,
            'over_90' => $aging->over90,
        ];

        $biggest = max(array_map('abs', $values));
        $total = $aging->total();
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = new AgingRow(
                key: $key,
                value: $value,
                share: $total == 0.0 ? 0.0 : round($value / $total * 100, 1),
                width: self::width($value, $biggest),
                negative: $value < 0,
            );
        }

        return $rows;
    }

    private static function width(float $value, float $biggest): float
    {
        if ($value == 0.0 || $biggest == 0.0) {
            return 0.0;
        }

        return max(self::MIN_WIDTH, round(abs($value) / $biggest * 100, 1));
    }
}
