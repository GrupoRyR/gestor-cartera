<?php

namespace App\Modules\Comisiones\Infrastructure\Export;

use App\Modules\Comisiones\Domain\Calculation\PersonRole;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedReport;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedRow;
use App\Modules\Comisiones\Infrastructure\Report\Labels;
use App\Support\Formatting\Money as MoneyFormat;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The consolidated report (§8.1) as an Excel workbook: one sheet with the
 * title, the version and its status, then the three sections and the
 * summary per person, the same rows as the screen. Amounts are numbers.
 */
final class ConsolidatedSpreadsheet
{
    public function write(string $path, ConsolidatedReport $report): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(__('comisiones::consolidado.hoja'));
        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(36, 2);
        $sheet->setColumnWidth(18, 3, 4, 5, 6, 7, 8, 9);

        $writer->addRow(Cells::bold([$report->title]));
        $writer->addRow(Cells::row([Cells::text($report->versionLine())]));
        $writer->addRow(Cells::blank());

        $this->regular($writer, $report);
        $this->separate($writer, $report);
        $this->directors($writer, $report);
        $this->persons($writer, $report);

        $writer->close();
    }

    private function regular(Writer $writer, ConsolidatedReport $report): void
    {
        $columns = ['codigo', 'vendedor', 'venta', 'recaudo', 'obr', 'reintegros', 'descuentos', ...($report->hasDirectorColumn ? ['director'] : []), 'total'];

        $writer->addRow(Cells::bold([__('comisiones::consolidado.regular.titulo')]));
        $writer->addRow(Cells::bold(array_map(fn (string $column): string => __('comisiones::consolidado.regular.columnas.'.$column), $columns)));

        foreach ($report->regular as $row) {
            $writer->addRow(Cells::row($this->regularCells($row, $report->hasDirectorColumn, bold: false)));
        }

        $writer->addRow(Cells::row($this->regularCells($report->regularTotal, $report->hasDirectorColumn, bold: true)));
        $writer->addRow(Cells::blank());
    }

    /**
     * @return list<Cell>
     */
    private function regularCells(ConsolidatedRow $row, bool $withDirector, bool $bold): array
    {
        return [
            Cells::text($bold ? $row->name : $row->reference, $bold),
            Cells::text($bold ? null : $row->name),
            Cells::money($row->saleCommission, $bold),
            Cells::money($row->collectionCommission, $bold),
            Cells::money($row->obrCommission, $bold),
            Cells::money($row->reimbursements, $bold),
            Cells::money($row->deductions, $bold),
            ...($withDirector ? [Cells::money($row->director, $bold)] : []),
            Cells::money($row->total, $bold),
        ];
    }

    private function separate(Writer $writer, ConsolidatedReport $report): void
    {
        $writer->addRow(Cells::bold([__('comisiones::consolidado.especiales.titulo')]));
        $writer->addRow(Cells::bold(array_map(
            fn (string $column): string => __('comisiones::consolidado.especiales.columnas.'.$column),
            ['codigo', 'nombre', 'venta', 'comision', 'total'],
        )));

        foreach ($report->separate as $code) {
            $writer->addRow(Cells::row([
                Cells::text((string) $code->code()),
                Cells::text($code->rule->name),
                Cells::money($code->sales),
                Cells::money($code->saleCommission),
                Cells::money($code->total),
            ]));
        }

        $writer->addRow(Cells::blank());
    }

    private function directors(Writer $writer, ConsolidatedReport $report): void
    {
        $writer->addRow(Cells::bold([__('comisiones::consolidado.directores.titulo')]));

        foreach ($report->directors as $director) {
            $team = $director->team;
            $writer->addRow(Cells::bold([__('comisiones::consolidado.directores.equipo', ['canal' => $team->channel, 'director' => $team->director->name])]));
            $writer->addRow(Cells::row([Cells::text(__('comisiones::consolidado.directores.tasa', ['tasa' => Labels::rate($team->rate)]))]));

            if ($director->paidWithCode !== null) {
                $writer->addRow(Cells::row([Cells::text(__('comisiones::consolidado.directores.con_codigo', ['codigo' => $director->paidWithCode]))]));
            }

            $writer->addRow(Cells::bold(array_map(
                fn (string $column): string => __('comisiones::consolidado.directores.columnas.'.$column),
                ['codigo', 'nombre', 'recaudo', 'venta'],
            )));

            foreach ($director->rows as $member) {
                $writer->addRow(Cells::row([
                    Cells::text((string) $member->code),
                    Cells::text($member->name),
                    Cells::money($member->collected),
                    Cells::money($member->sales),
                ]));
            }

            $writer->addRow(Cells::row([
                Cells::text(__('comisiones::consolidado.directores.total'), true),
                Cells::empty(),
                Cells::money($director->collected, true),
                Cells::money($director->sales, true),
            ]));
            $writer->addRow(Cells::row([
                Cells::text(__('comisiones::consolidado.directores.comision'), true),
                Cells::empty(),
                Cells::money($director->collectionCommission, true),
                Cells::money($director->salesCommission, true),
            ]));
            $writer->addRow(Cells::row([
                Cells::text(__('comisiones::consolidado.directores.nomina'), true),
                Cells::empty(),
                Cells::empty(),
                Cells::money($director->total, true),
            ]));
            $writer->addRow(Cells::blank());
        }
    }

    private function persons(Writer $writer, ConsolidatedReport $report): void
    {
        $writer->addRow(Cells::bold([__('comisiones::consolidado.personas.titulo')]));
        $writer->addRow(Cells::bold(array_map(
            fn (string $column): string => __('comisiones::consolidado.personas.columnas.'.$column),
            ['persona', 'roles', 'total'],
        )));

        foreach ($report->persons as $person) {
            $roles = array_map(
                fn (PersonRole $role): string => $role->label.': '.MoneyFormat::format($role->amount->amount()),
                $person->roles,
            );

            $writer->addRow(Cells::row([
                Cells::text($person->person->name),
                Cells::text(implode('; ', $roles)),
                Cells::money($person->total),
            ]));
        }

        $writer->addRow(Cells::row([
            Cells::text(__('comisiones::consolidado.personas.total'), true),
            Cells::empty(),
            Cells::money($report->payrollTotal, true),
        ]));
    }
}
