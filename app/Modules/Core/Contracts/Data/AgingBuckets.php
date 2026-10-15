<?php

namespace App\Modules\Core\Contracts\Data;

/**
 * Receivables aging (edades de la deuda): sums of open invoice balances by
 * Invoice::$daysOverdue, in Colombian pesos.
 *
 * Buckets follow the due date only; the company's grace days do not move
 * an invoice between buckets.
 */
final readonly class AgingBuckets
{
    /**
     * @param  float  $notDue  daysOverdue <= 0.
     * @param  float  $days1To30  daysOverdue 1 to 30.
     * @param  float  $days31To60  daysOverdue 31 to 60.
     * @param  float  $days61To90  daysOverdue 61 to 90.
     * @param  float  $over90  daysOverdue above 90.
     */
    public function __construct(
        public float $notDue = 0,
        public float $days1To30 = 0,
        public float $days31To60 = 0,
        public float $days61To90 = 0,
        public float $over90 = 0,
    ) {}

    /**
     * Sum of every bucket.
     */
    public function total(): float
    {
        return $this->notDue + $this->overdue();
    }

    /**
     * Sum of every bucket except $notDue.
     */
    public function overdue(): float
    {
        return $this->days1To30 + $this->days31To60 + $this->days61To90 + $this->over90;
    }
}
