<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;
use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Calculation\DirectorSettlement;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The consolidated report of one settlement version (§8.1), built once and
 * drawn by the screen, the Excel workbook and the PDF, so the three always
 * show the same rows and totals:
 *
 * - section 1, the regular team: every regular code with activity or
 *   something to pay, then the derived commissions (81.1, 85.1), then the
 *   total, which is SettlementResult::regularTotal();
 * - section 2, the codes settled apart (81, 84, 90);
 * - section 3, the channel directors, one block per team;
 * - the annex, the total per person reported to payroll (§5.7).
 */
final readonly class ConsolidatedReport
{
    /**
     * @param  list<ConsolidatedRow>  $regular
     * @param  list<CodeSettlement>  $separate
     * @param  list<DirectorSettlement>  $directors
     * @param  list<PersonSettlement>  $persons
     * @param  bool  $hasDirectorColumn  Whether a director commission is placed on a regular code (§14.4).
     */
    public function __construct(
        public Settlement $settlement,
        public string $title,
        public string $status,
        public array $regular,
        public ConsolidatedRow $regularTotal,
        public array $separate,
        public array $directors,
        public array $persons,
        public Money $payrollTotal,
        public bool $hasDirectorColumn,
    ) {}

    public static function of(Settlement $settlement): self
    {
        $result = $settlement->result;
        $rows = [];

        foreach ($result->regular() as $code) {
            if ($code->hasActivity() || ! $code->total->isZero()) {
                $rows[] = new ConsolidatedRow(
                    reference: (string) $code->code(),
                    name: $code->rule->name,
                    code: $code->code(),
                    saleCommission: $code->saleCommission,
                    collectionCommission: $code->collectionCommission,
                    obrCommission: $code->obrCommission,
                    reimbursements: $code->reimbursements,
                    deductions: $code->deductions,
                    director: $code->directorCommission,
                    total: $code->total,
                );
            }
        }

        foreach ($result->derivedCommissions() as $derived) {
            $rows[] = self::derivedRow($derived);
        }

        $sum = fn (callable $amount): Money => Money::sum(array_map($amount, $rows));
        $total = new ConsolidatedRow(
            reference: '',
            name: __('comisiones::consolidado.regular.total'),
            code: null,
            saleCommission: $sum(fn (ConsolidatedRow $row): Money => $row->saleCommission),
            collectionCommission: $sum(fn (ConsolidatedRow $row): Money => $row->collectionCommission),
            obrCommission: $sum(fn (ConsolidatedRow $row): Money => $row->obrCommission),
            reimbursements: $sum(fn (ConsolidatedRow $row): Money => $row->reimbursements),
            deductions: $sum(fn (ConsolidatedRow $row): Money => $row->deductions),
            director: $sum(fn (ConsolidatedRow $row): Money => $row->director),
            total: $result->regularTotal(),
        );

        return new self(
            settlement: $settlement,
            title: Labels::reportTitle($settlement->period),
            status: Labels::status($settlement->status),
            regular: $rows,
            regularTotal: $total,
            separate: array_values($result->separate()),
            directors: $result->directors(),
            persons: $result->persons(),
            payrollTotal: Money::sum(array_map(fn (PersonSettlement $person): Money => $person->total, $result->persons())),
            hasDirectorColumn: ! $total->director->isZero(),
        );
    }

    /** "Versión 1 · revisión 1 · Borrador": the version line under the title. */
    public function versionLine(): string
    {
        return __('comisiones::comun.version_revision', [
            'version' => $this->settlement->version,
            'revision' => $this->settlement->revision,
        ]).' · '.$this->status;
    }

    /** "Consolidado_Comisiones_2026-07_v1.<extension>". */
    public function fileName(string $extension): string
    {
        return sprintf('Consolidado_Comisiones_%s_v%d.%s', $this->settlement->period->key(), $this->settlement->version, $extension);
    }

    /**
     * A derived commission as a row of section 1: its commission goes to the
     * sale or the collection column after its base.
     */
    private static function derivedRow(DerivedSettlement $derived): ConsolidatedRow
    {
        $zero = Money::zero();
        $onSales = $derived->rule->base === DerivedBase::Sales;

        return new ConsolidatedRow(
            reference: $derived->rule->key,
            name: $derived->rule->label,
            code: null,
            saleCommission: $onSales ? $derived->commission : $zero,
            collectionCommission: $onSales ? $zero : $derived->commission,
            obrCommission: $zero,
            reimbursements: $zero,
            deductions: $zero,
            director: $zero,
            total: $derived->commission,
        );
    }
}
