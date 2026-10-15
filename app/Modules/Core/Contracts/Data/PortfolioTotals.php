<?php

namespace App\Modules\Core\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * Portfolio figures of one receivables snapshot, limited to the caller's
 * scope and query. Amounts are Colombian pesos.
 */
final readonly class PortfolioTotals
{
    /**
     * @param  CarbonImmutable|null  $cutoffDate  Snapshot cutoff date (corte); null when there is no snapshot.
     * @param  float  $total  Sum of open invoice balances.
     * @param  float  $overdue  Sum of balances of invoices with daysOverdue > 0.
     * @param  int  $clientCount  Clients in scope that match the query.
     * @param  int  $overdueClientCount  Those of them with at least one overdue invoice.
     */
    public function __construct(
        public ?CarbonImmutable $cutoffDate,
        public float $total,
        public float $overdue,
        public int $clientCount,
        public int $overdueClientCount,
        public AgingBuckets $aging,
    ) {}

    /**
     * Totals with no snapshot: every figure is zero.
     */
    public static function empty(): self
    {
        return new self(
            cutoffDate: null,
            total: 0.0,
            overdue: 0.0,
            clientCount: 0,
            overdueClientCount: 0,
            aging: new AgingBuckets,
        );
    }

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
