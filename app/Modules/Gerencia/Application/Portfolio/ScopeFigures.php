<?php

namespace App\Modules\Gerencia\Application\Portfolio;

use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Domain\Portfolio\MonthPoint;
use App\Modules\Gerencia\Domain\Portfolio\PortfolioRotation;

/**
 * The portfolio figures of one scope of the dashboard: the whole group or
 * one company.
 */
final readonly class ScopeFigures
{
    /**
     * @param  CompanyCode|null  $company  Null for the group.
     * @param  list<MonthPoint>  $trend
     */
    public function __construct(
        public ?CompanyCode $company,
        public string $name,
        public PortfolioTotals $totals,
        public array $trend,
        public PortfolioRotation $rotation,
    ) {}
}
