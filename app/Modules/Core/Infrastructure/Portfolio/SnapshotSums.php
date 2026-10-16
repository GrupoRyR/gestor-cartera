<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Application\Portfolio\Cents;
use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use Carbon\CarbonImmutable;

/**
 * The figures of one or more receivables snapshots for some clients, in
 * exact integer cents until they become PortfolioTotals.
 */
final readonly class SnapshotSums
{
    public function __construct(
        public int $total = 0,
        public int $overdue = 0,
        public int $notDue = 0,
        public int $days1To30 = 0,
        public int $days31To60 = 0,
        public int $days61To90 = 0,
        public int $over90 = 0,
        public int $clientCount = 0,
        public int $overdueClientCount = 0,
    ) {}

    /**
     * Both sums together, e.g. two companies in the group view. Their
     * clients never overlap: a client belongs to one company.
     */
    public function plus(self $other): self
    {
        return new self(
            total: $this->total + $other->total,
            overdue: $this->overdue + $other->overdue,
            notDue: $this->notDue + $other->notDue,
            days1To30: $this->days1To30 + $other->days1To30,
            days31To60: $this->days31To60 + $other->days31To60,
            days61To90: $this->days61To90 + $other->days61To90,
            over90: $this->over90 + $other->over90,
            clientCount: $this->clientCount + $other->clientCount,
            overdueClientCount: $this->overdueClientCount + $other->overdueClientCount,
        );
    }

    /**
     * The contract figures, in pesos.
     */
    public function toTotals(?CarbonImmutable $cutoffDate): PortfolioTotals
    {
        return new PortfolioTotals(
            cutoffDate: $cutoffDate,
            total: Cents::toFloat($this->total),
            overdue: Cents::toFloat($this->overdue),
            clientCount: $this->clientCount,
            overdueClientCount: $this->overdueClientCount,
            aging: new AgingBuckets(
                notDue: Cents::toFloat($this->notDue),
                days1To30: Cents::toFloat($this->days1To30),
                days31To60: Cents::toFloat($this->days31To60),
                days61To90: Cents::toFloat($this->days61To90),
                over90: Cents::toFloat($this->over90),
            ),
        );
    }
}
