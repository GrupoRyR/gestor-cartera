<?php

namespace App\Modules\Gerencia\Application\Board;

use App\Modules\Gerencia\Application\Collections\CollectionsReport;
use App\Modules\Gerencia\Application\Commitments\ComplianceReport;
use App\Modules\Gerencia\Application\Mail\MailResultsReport;
use App\Modules\Gerencia\Application\Portfolio\ScopeFigures;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Everything the management dashboard shows for one period and one scope.
 */
final readonly class ManagementBoard
{
    /**
     * @param  string  $scopeName  Active company name, or the group name in the group view.
     * @param  list<ScopeFigures>  $scopes  The group first when the user sees two companies or more, then one per company.
     */
    public function __construct(
        public CarbonImmutable $today,
        public ReportPeriod $period,
        public string $scopeName,
        public array $scopes,
        public ComplianceReport $compliance,
        public MailResultsReport $mail,
        public CollectionsReport $collections,
    ) {}

    /**
     * Whether any scope has a portfolio snapshot to show.
     */
    public function hasPortfolio(): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->totals->cutoffDate !== null) {
                return true;
            }
        }

        return false;
    }
}
