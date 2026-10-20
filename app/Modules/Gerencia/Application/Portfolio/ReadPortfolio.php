<?php

namespace App\Modules\Gerencia\Application\Portfolio;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Collections\CollectionsReport;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use App\Modules\Gerencia\Domain\Portfolio\MonthlyTrend;
use App\Modules\Gerencia\Domain\Portfolio\PortfolioRotation;
use Carbon\CarbonImmutable;

/**
 * Reads the portfolio totals, the monthly trend and the rotation of every
 * scope of the dashboard: the group (when the user sees two companies or
 * more) followed by each company in the user's scope.
 */
final class ReadPortfolio
{
    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly CurrentCompany $company,
    ) {}

    /**
     * @return list<ScopeFigures> Empty when the user sees no company.
     */
    public function handle(ReportPeriod $period, CarbonImmutable $today, CollectionsReport $collections): array
    {
        $companies = $this->company->scope();

        if ($companies === []) {
            return [];
        }

        $scopes = [];

        if (count($companies) > 1) {
            $scopes[] = $this->figures(
                company: null,
                name: (string) config('gerencia.group_name', 'Grupo RYR'),
                query: null,
                period: $period,
                today: $today,
                collected: $collections->total(),
            );
        }

        foreach ($companies as $company) {
            $scopes[] = $this->figures(
                company: $company->code,
                name: $company->name,
                query: new ClientQuery(companies: [$company->code]),
                period: $period,
                today: $today,
                collected: $collections->total($company->code),
            );
        }

        return $scopes;
    }

    private function figures(
        ?CompanyCode $company,
        string $name,
        ?ClientQuery $query,
        ReportPeriod $period,
        CarbonImmutable $today,
        float $collected,
    ): ScopeFigures {
        $totals = $this->cartera->totals($query);
        $history = $this->cartera->history($this->historyStart($period, $today), $today, $query);

        return new ScopeFigures(
            company: $company,
            name: $name,
            totals: $totals,
            trend: MonthlyTrend::fromHistory($history),
            rotation: PortfolioRotation::from($collected, $this->averagePortfolio($history, $period, $totals), $period->days()),
        );
    }

    /**
     * One read covers both the trend window and the period.
     */
    private function historyStart(ReportPeriod $period, CarbonImmutable $today): CarbonImmutable
    {
        $months = max(1, (int) config('gerencia.trend_months', 12));
        $trendStart = $today->startOfMonth()->subMonths($months - 1);

        return $trendStart->lessThan($period->from) ? $trendStart : $period->from;
    }

    /**
     * Mean portfolio of the snapshots inside the period; the current
     * portfolio when there is none.
     *
     * @param  list<PortfolioTotals>  $history
     */
    private function averagePortfolio(array $history, ReportPeriod $period, PortfolioTotals $current): float
    {
        $totals = [];

        foreach ($history as $snapshot) {
            if ($snapshot->cutoffDate !== null && $period->contains($snapshot->cutoffDate)) {
                $totals[] = $snapshot->total;
            }
        }

        return $totals === [] ? $current->total : array_sum($totals) / count($totals);
    }
}
