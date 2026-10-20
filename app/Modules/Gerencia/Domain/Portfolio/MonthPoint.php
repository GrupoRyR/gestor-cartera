<?php

namespace App\Modules\Gerencia\Domain\Portfolio;

use Carbon\CarbonImmutable;

/**
 * The portfolio at the end of one calendar month: the last snapshot of it.
 */
final readonly class MonthPoint
{
    /**
     * @param  CarbonImmutable  $month  First day of the calendar month.
     * @param  CarbonImmutable  $cutoffDate  Cutoff date of the snapshot that stands for the month.
     */
    public function __construct(
        public CarbonImmutable $month,
        public CarbonImmutable $cutoffDate,
        public float $total,
        public float $overdue,
    ) {}

    /**
     * Overdue share of the total, from 0 to 100; 0 when the total is not positive.
     */
    public function overduePercent(): float
    {
        if ($this->total <= 0) {
            return 0.0;
        }

        return $this->overdue / $this->total * 100;
    }
}
