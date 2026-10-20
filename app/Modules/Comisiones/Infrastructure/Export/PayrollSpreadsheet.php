<?php

namespace App\Modules\Comisiones\Infrastructure\Export;

use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Infrastructure\Report\Labels;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The payroll file of a settlement version (§3, §5.7, §8.1 annex): the
 * title and the version line, then per person one row for each role they
 * are paid for (kind, reference, label, amount) and a row with their total,
 * the «valor pago nómina»; the grand total closes the sheet. Amounts are
 * numbers, so payroll can add them up.
 */
final class PayrollSpreadsheet
{
    /** "Nomina_Comisiones_2026-08_v1.xlsx". */
    public static function fileName(Settlement $settlement): string
    {
        return sprintf('Nomina_Comisiones_%s_v%d.xlsx', $settlement->period->key(), $settlement->version);
    }

    public function write(string $path, Settlement $settlement): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(__('comisiones::informes.nomina.hoja'));
        $sheet->setColumnWidth(30, 1);
        $sheet->setColumnWidth(12, 2, 3);
        $sheet->setColumnWidth(40, 4);
        $sheet->setColumnWidth(18, 5);

        $writer->addRow(Cells::bold([__('comisiones::informes.nomina.titulo', ['mes' => Labels::month($settlement->period)])]));
        $writer->addRow(Cells::row([Cells::text(__('comisiones::comun.version_revision', [
            'version' => $settlement->version,
            'revision' => $settlement->revision,
        ]).' · '.Labels::status($settlement->status))]));
        $writer->addRow(Cells::blank());
        $writer->addRow(Cells::bold(array_map(
            fn (string $column): string => __('comisiones::informes.nomina.columnas.'.$column),
            ['persona', 'tipo', 'referencia', 'concepto', 'valor'],
        )));

        $persons = $settlement->result->persons();

        foreach ($persons as $person) {
            $name = $person->person->name;

            foreach ($person->roles as $role) {
                $writer->addRow(Cells::row([
                    Cells::text($name),
                    Cells::text($role->kind->label()),
                    Cells::text($role->reference),
                    Cells::text($role->label),
                    Cells::money($role->amount),
                ]));
            }

            $writer->addRow(Cells::row([
                Cells::text($name, true),
                Cells::empty(),
                Cells::empty(),
                Cells::text(__('comisiones::informes.nomina.valor_pago'), true),
                Cells::money($person->total, true),
            ]));
        }

        $writer->addRow(Cells::row([
            Cells::text(__('comisiones::informes.nomina.total'), true),
            Cells::empty(),
            Cells::empty(),
            Cells::empty(),
            Cells::money(Money::sum(array_map(fn (PersonSettlement $person): Money => $person->total, $persons)), true),
        ]));

        $writer->close();
    }
}
