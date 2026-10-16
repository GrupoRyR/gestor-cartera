<?php

namespace App\Modules\Cartera\Application\Dashboard;

use App\Modules\Cartera\Domain\Dashboard\OverdueRisk;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\PortfolioTotals;

/**
 * The totals of one company, one side of the SGX against SDC comparison.
 */
final readonly class CompanyFigures
{
    public OverdueRisk $risk;

    public function __construct(
        public Company $company,
        public PortfolioTotals $totals,
    ) {
        $this->risk = OverdueRisk::forPercent($totals->overduePercent());
    }
}
