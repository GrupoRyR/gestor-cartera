<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Calculation\DerivedSettlement;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Settlement\Settlement;

/**
 * The collection reports of a settlement version (§8.2):
 *
 * - one per seller code with payments or crossings in the period, in code
 *   order; codes without collections get none, and neither do the control
 *   codes (80, 100), which are not sellers and never earn a commission;
 * - then one per commission derived from a code's collections (85.1), with
 *   the source lines re-rated at the flat derived rate.
 *
 * The report list, the PDFs and the §9.3 control are all built from here,
 * so they always agree.
 */
final class CollectionReports
{
    /**
     * @return list<CollectionReport>
     */
    public function of(Settlement $settlement): array
    {
        $result = $settlement->result;
        $reports = [];

        foreach ($result->codes() as $code) {
            if ($code->rule->scope === CodeScope::Control || $code->collectionDetails === []) {
                continue;
            }

            $reports[] = new CollectionReport(
                reference: (string) $code->code(),
                seller: $code->rule->name,
                lineSeller: $code->rule->name,
                period: $settlement->period,
                version: $settlement->version,
                lines: $code->collectionDetails,
                expected: $code->collectionCommission,
                showsDays: true,
                note: null,
            );
        }

        foreach ($result->derivedCommissions() as $derived) {
            if ($derived->rule->base === DerivedBase::Collections && $derived->details !== []) {
                $reports[] = $this->derived($settlement, $derived);
            }
        }

        return $reports;
    }

    /**
     * The report of one reference, null when the version has none.
     */
    public function find(Settlement $settlement, string $reference): ?CollectionReport
    {
        foreach ($this->of($settlement) as $report) {
            if ($report->reference === $reference) {
                return $report;
            }
        }

        return null;
    }

    /**
     * A derived report (85.1): paid to the derived person, the lines of the
     * source code, the days shown as «—» and the note of §8.2 built from the
     * rule (source code, its person, the flat rate).
     */
    private function derived(Settlement $settlement, DerivedSettlement $derived): CollectionReport
    {
        $rule = $derived->rule;
        $source = self::sourceName($settlement->result, $rule->sourceCode);

        return new CollectionReport(
            reference: $rule->key,
            seller: $rule->person->name,
            lineSeller: $source,
            period: $settlement->period,
            version: $settlement->version,
            lines: $derived->details,
            expected: $derived->commission,
            showsDays: false,
            note: __('comisiones::informes.pdf.nota_especial', [
                'codigo' => $rule->sourceCode,
                'nombre' => $source,
                'tasa' => Labels::percent($rule->rate),
            ]),
        );
    }

    /** The person of the source code ("Liliam Herrera"), or the code itself. */
    private static function sourceName(SettlementResult $result, int $code): string
    {
        $rule = $result->code($code)->rule ?? $result->parameters()->code($code);

        return $rule?->personRef()->name ?? (string) $code;
    }
}
