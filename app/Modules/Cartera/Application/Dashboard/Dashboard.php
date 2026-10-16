<?php

namespace App\Modules\Cartera\Application\Dashboard;

use App\Modules\Cartera\Domain\Dashboard\AgingSlice;
use App\Modules\Cartera\Domain\Dashboard\OverdueRisk;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use Carbon\CarbonImmutable;

/**
 * Everything the dashboard shows, for the companies in the user's current view.
 */
final readonly class Dashboard
{
    public OverdueRisk $risk;

    /**
     * @param  string  $scopeLabel  Name of the active company, or of the group.
     * @param  PortfolioTotals  $totals  Total, overdue and age of the debt.
     * @param  list<AgingSlice>  $aging  The overdue bands of $totals.
     * @param  list<CompanyFigures>  $comparison  One entry per company in the group view; empty otherwise.
     * @param  list<ClientSummary>  $topDebtors  The clients owing the most overdue money, largest first.
     * @param  list<Commitment>  $weekCommitments  Pending commitments due from today to the end of the week, earliest first.
     * @param  int  $weekCommitmentsCount  How many pending commitments fall in the week (may exceed the list).
     * @param  float  $weekCommitmentsAmount  Money promised by the listed commitments.
     * @param  int  $unmanagedOverdue  Overdue clients nobody has managed for $unmanagedDays days.
     * @param  list<Activity>  $recentActivity  The latest entries of the clients' logs, system ones left out.
     */
    public function __construct(
        public CarbonImmutable $today,
        public string $scopeLabel,
        public bool $isGroup,
        public PortfolioTotals $totals,
        public array $aging,
        public array $comparison,
        public array $topDebtors,
        public array $weekCommitments,
        public int $weekCommitmentsCount,
        public float $weekCommitmentsAmount,
        public int $unmanagedOverdue,
        public int $unmanagedDays,
        public array $recentActivity,
    ) {
        $this->risk = OverdueRisk::forPercent($totals->overduePercent());
    }

    public function hasPortfolio(): bool
    {
        return $this->totals->cutoffDate !== null || $this->totals->clientCount > 0;
    }
}
