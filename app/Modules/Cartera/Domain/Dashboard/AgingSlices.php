<?php

namespace App\Modules\Cartera\Domain\Dashboard;

use App\Modules\Core\Contracts\Data\AgingBuckets;

/**
 * Turns the age buckets of a portfolio into the slices of the overdue chart.
 * What is not due yet is left out: the chart is about the debt that is late.
 */
final class AgingSlices
{
    /**
     * The four overdue bands, youngest first.
     *
     * @return list<AgingSlice>
     */
    public static function from(AgingBuckets $buckets): array
    {
        $overdue = $buckets->overdue();

        return array_map(
            fn (AgingBand $band): AgingSlice => new AgingSlice(
                band: $band,
                amount: $band->amountIn($buckets),
                share: $overdue > 0 ? round($band->amountIn($buckets) / $overdue * 100, 2) : 0.0,
            ),
            AgingBand::cases(),
        );
    }
}
