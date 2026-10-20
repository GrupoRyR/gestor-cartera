<?php

namespace App\Modules\Gerencia\Application\Board;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Gerencia\Application\Collections\ReadCollections;
use App\Modules\Gerencia\Application\Commitments\ReadCompliance;
use App\Modules\Gerencia\Application\Mail\ReadMailResults;
use App\Modules\Gerencia\Application\Portfolio\ReadPortfolio;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Reads the figures of the management dashboard (Part B, module 6) through
 * the Core contracts, each one already limited to the user's scope.
 */
final class BuildManagementBoard
{
    public function __construct(
        private readonly ReadCollections $collections,
        private readonly ReadPortfolio $portfolio,
        private readonly ReadCompliance $compliance,
        private readonly ReadMailResults $mail,
        private readonly CurrentCompany $company,
    ) {}

    public function handle(PeriodKey $key): ManagementBoard
    {
        $today = CarbonImmutable::today();
        $period = ReportPeriod::for($key, $today);
        $collections = $this->collections->handle($period);

        return new ManagementBoard(
            today: $today,
            period: $period,
            scopeName: $this->company->active()->name ?? (string) config('gerencia.group_name', 'Grupo RYR'),
            scopes: $this->portfolio->handle($period, $today, $collections),
            compliance: $this->compliance->handle($period, $today),
            mail: $this->mail->handle($period),
            collections: $collections,
        );
    }
}
