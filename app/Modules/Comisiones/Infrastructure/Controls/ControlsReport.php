<?php

namespace App\Modules\Comisiones\Infrastructure\Controls;

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\ControlTotals;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReport;
use Carbon\CarbonImmutable;

/**
 * The controls of §9 for one settlement version (§7.8), with the figures
 * the screen shows next to each status.
 */
final readonly class ControlsReport
{
    /**
     * @param  list<CollectionReport>  $reports  The collection reports of the version (§8.2).
     * @param  Money  $consolidatedCollections  The collection commission of the consolidated report:
     *                                          every code (control codes aside) plus the derived
     *                                          commissions on collections (85.1).
     * @param  int  $storedReports  Collection PDFs stored for the version.
     * @param  CarbonImmutable  $loadsFrom  First cutoff day of the loads counted for the period.
     * @param  CarbonImmutable  $loadsTo  Last cutoff day: the period end plus the 7 days a daily
     *                                    extraction still covers (Part B, B.0).
     * @param  list<LoadSummary>  $loads
     * @param  array<string, int>  $staged  Dataset value => staged rows dated in the period.
     * @param  int  $manualCrossings  Manual crossings dated in the period, any review state.
     * @param  list<AlertGroup>  $alertGroups  Blocking kinds first.
     * @param  list<array{alert: Alert, justification: string|null}>  $blocking
     */
    public function __construct(
        public Settlement $settlement,
        public ControlTotals $totals,
        public array $reports,
        public Money $consolidatedCollections,
        public int $storedReports,
        public CarbonImmutable $loadsFrom,
        public CarbonImmutable $loadsTo,
        public array $loads,
        public array $staged,
        public int $manualCrossings,
        public int $duplicates,
        public array $alertGroups,
        public array $blocking,
        public int $paymentsWithoutInvoiceDate,
    ) {}

    /** §9.1: loaded sales = sales by code, control codes included. */
    public function sales(): ControlStatus
    {
        return ControlStatus::of($this->totals->salesReconcile());
    }

    /** §9.2: loaded collections = settled collections, payments and crossings. */
    public function collections(): ControlStatus
    {
        return ControlStatus::of($this->totals->collectionsReconcile());
    }

    /** Sum of the totals of the collection PDFs. */
    public function reportsTotal(): Money
    {
        return Money::sum(array_map(fn (CollectionReport $report): Money => $report->total(), $this->reports));
    }

    /**
     * @return list<CollectionReport> The reports whose total differs from their consolidated figure.
     */
    public function mismatchedReports(): array
    {
        return array_values(array_filter($this->reports, fn (CollectionReport $report): bool => ! $report->matches()));
    }

    /** §9.3: the PDF totals add up to the collection commission of the consolidated report, each one matching. */
    public function reportsReconcile(): ControlStatus
    {
        return ControlStatus::of($this->mismatchedReports() === [] && $this->reportsTotal()->equals($this->consolidatedCollections));
    }

    /** §9.4: SAP delivers through the Ingesta robot, not CSV files with a .ctl control file. */
    public function controlFile(): ControlStatus
    {
        return ControlStatus::NotApplicable;
    }

    /** §9.5: no natural key twice; staging upserts by natural key, so reloads never duplicate. */
    public function idempotency(): ControlStatus
    {
        return ControlStatus::of($this->duplicates === 0);
    }

    /** §9.6: no blocking alert; warnings are listed but do not fail the control. */
    public function alerts(): ControlStatus
    {
        foreach ($this->alertGroups as $group) {
            if ($group->isBlocking()) {
                return ControlStatus::Fails;
            }
        }

        return ControlStatus::Meets;
    }

    /** §9.7: every blocking alert justified (as the approval requires). */
    public function justifications(): ControlStatus
    {
        foreach ($this->blocking as $entry) {
            if ($entry['justification'] === null) {
                return ControlStatus::Fails;
            }
        }

        return ControlStatus::Meets;
    }
}
