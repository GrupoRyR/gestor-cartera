<?php

namespace App\Modules\Comisiones\Infrastructure\Controls;

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Dashboard\LoadBoard;
use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\PaymentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SaleRecord;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReports;
use App\Modules\Comisiones\Infrastructure\Report\ReportArchive;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlementInputs;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the controls of §9 for one settlement version (§7.8):
 *
 * - §9.1 and §9.2 from the settlement's ControlTotals;
 * - §9.3 from the collection reports, the same computation the PDFs use.
 *   comisiones_documentos keeps no total per PDF, so the totals come from
 *   the stored result, not from the stored files; the screen says so and
 *   shows how many PDFs are stored;
 * - §9.4 does not apply (SAP sends rows through the Ingesta robot, with no
 *   .ctl file); the Ingesta loads staged for the period are shown instead;
 * - §9.5 from the staged rows of the period and the duplicate-line alerts;
 * - §9.6 and §9.7 from the alerts and justifications of the version, with
 *   the payments that had no invoice date.
 */
final class PeriodControls
{
    /** Days after the period end a daily extraction still covers (Part B, B.0). */
    private const int LATE_DAYS = 7;

    public function __construct(
        private readonly CollectionReports $reports,
        private readonly ReportArchive $archive,
        private readonly EloquentSettlementInputs $inputs,
    ) {}

    public function of(Settlement $settlement): ControlsReport
    {
        $result = $settlement->result;
        $period = $settlement->period;
        $from = CarbonImmutable::parse($period->start()->format('Y-m-d'));
        $to = CarbonImmutable::parse($period->end()->format('Y-m-d'))->addDays(self::LATE_DAYS);

        $consolidated = Money::sum(array_map(
            fn (CodeSettlement $code): Money => $code->collectionCommission,
            array_filter($result->codes(), fn (CodeSettlement $code): bool => $code->rule->scope !== CodeScope::Control),
        ))->plus(Money::sum(array_map(
            fn (DerivedSettlement $derived): Money => $derived->commission,
            array_filter($result->derivedCommissions(), fn (DerivedSettlement $derived): bool => $derived->rule->base === DerivedBase::Collections),
        )));

        $stored = array_filter(
            array_keys($this->archive->stored($settlement)),
            fn (string $key): bool => str_starts_with($key, ReportArchive::COLLECTION.':'),
        );

        return new ControlsReport(
            settlement: $settlement,
            totals: $result->controls(),
            reports: $this->reports->of($settlement),
            consolidatedCollections: $consolidated,
            storedReports: count($stored),
            loadsFrom: $from,
            loadsTo: $to,
            loads: $this->loads($from, $to),
            staged: [
                Dataset::Sales->value => self::countIn(SaleRecord::query(), 'fecha_contabilizacion', $period),
                Dataset::Collections->value => self::countIn(PaymentRecord::query(), 'fecha_pago', $period),
                Dataset::Offsets->value => self::countIn(CrossingRecord::query()->where('origen', CrossingRecord::SAP), 'fecha_cruce', $period),
            ],
            manualCrossings: self::countIn(CrossingRecord::query()->where('origen', CrossingRecord::MANUAL), 'fecha_cruce', $period),
            duplicates: count($result->alertsOf(AlertKind::DuplicateLine)),
            alertGroups: self::groups($result->alerts()),
            blocking: array_map(fn (Alert $alert): array => [
                'alert' => $alert,
                'justification' => $settlement->justifications[$alert->key()] ?? null,
            ], $result->blockingAlerts()),
            paymentsWithoutInvoiceDate: $this->inputs->paymentsWithoutInvoiceDate($period),
        );
    }

    /**
     * @return list<LoadSummary> One per dataset the module stages, in the dashboard order.
     */
    private function loads(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summaries = [];

        foreach (LoadBoard::DATASETS as $dataset) {
            $loads = CommissionLoad::query()
                ->where('dataset', $dataset->value)
                ->whereBetween('corte', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->get();

            $last = $loads->sortByDesc('recibida_en')->first();

            $summaries[] = new LoadSummary(
                dataset: $dataset,
                loads: $loads->count(),
                rows: (int) $loads->sum('filas'),
                inserted: (int) $loads->sum('insertadas'),
                updated: (int) $loads->sum('actualizadas'),
                lastReceived: $last?->recibida_en,
            );
        }

        return $summaries;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function countIn(Builder $query, string $column, Period $period): int
    {
        return $query->whereBetween($column, [$period->start()->format('Y-m-d'), $period->end()->format('Y-m-d')])->count();
    }

    /**
     * @param  list<Alert>  $alerts
     * @return list<AlertGroup>
     */
    private static function groups(array $alerts): array
    {
        $counts = [];

        foreach ($alerts as $alert) {
            $counts[$alert->kind->value] = ($counts[$alert->kind->value] ?? 0) + 1;
        }

        $groups = array_map(fn (string $kind, int $count): AlertGroup => new AlertGroup(AlertKind::from($kind), $count), array_keys($counts), $counts);
        usort($groups, fn (AlertGroup $a, AlertGroup $b): int => [$b->isBlocking(), $b->count] <=> [$a->isBlocking(), $a->count]);

        return $groups;
    }
}
