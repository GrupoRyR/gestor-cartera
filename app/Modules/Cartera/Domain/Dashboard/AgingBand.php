<?php

namespace App\Modules\Cartera\Domain\Dashboard;

use App\Modules\Core\Contracts\Data\AgingBuckets;

/**
 * The overdue bands of the debt age chart: days past the due date.
 */
enum AgingBand: string
{
    case Days1To30 = '1_30';

    case Days31To60 = '31_60';

    case Days61To90 = '61_90';

    case Over90 = 'mas_90';

    public function amountIn(AgingBuckets $buckets): float
    {
        return match ($this) {
            self::Days1To30 => $buckets->days1To30,
            self::Days31To60 => $buckets->days31To60,
            self::Days61To90 => $buckets->days61To90,
            self::Over90 => $buckets->over90,
        };
    }

    /**
     * Position of the band on a scale from 1 (youngest debt) to 4 (oldest).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Days1To30 => 1,
            self::Days31To60 => 2,
            self::Days61To90 => 3,
            self::Over90 => 4,
        };
    }
}
