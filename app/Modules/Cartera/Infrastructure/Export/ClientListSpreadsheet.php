<?php

namespace App\Modules\Cartera\Infrastructure\Export;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The client list as an Excel workbook, one row per client, with the same
 * columns the list shows plus the company and the SAP code. Amounts are
 * numbers, so the analyst can filter and add them up.
 *
 * It reads the clients lazily and writes them as it goes, so a large
 * portfolio does not have to fit in memory.
 */
final class ClientListSpreadsheet
{
    public function __construct(private readonly CarteraRepository $cartera) {}

    /**
     * Writes the workbook to $path.
     *
     * @return int How many clients it holds.
     */
    public function write(string $path, ClientQuery $query): int
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(__('cartera::exportar.lista.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(10, 1);
        $sheet->setColumnWidth(16, 2);
        $sheet->setColumnWidth(42, 3);
        $sheet->setColumnWidth(14, 4);
        $sheet->setColumnWidth(18, 5, 6, 7);
        $sheet->setColumnWidth(14, 8);
        $sheet->setColumnWidth(26, 9, 10);
        $sheet->setColumnWidth(16, 11);

        $writer->addRow(Cells::header(array_map(
            fn (string $column): string => __('cartera::exportar.lista.columnas.'.$column),
            ['empresa', 'codigo', 'cliente', 'nit', 'estado', 'total', 'vencido', 'dias', 'asesor', 'gestor', 'ultima_gestion'],
        )));

        $rows = 0;

        foreach ($this->cartera->allClients($query) as $client) {
            $writer->addRow(Cells::row([
                Cells::text($client->company->value),
                Cells::text($client->code),
                Cells::text($client->name),
                Cells::text($client->taxId),
                Cells::text($client->status->label()),
                Cells::money($client->balance),
                Cells::money($client->overdue),
                Cells::integer($client->maxDaysOverdue),
                Cells::text($client->advisor?->name),
                Cells::text($client->manager?->name),
                Cells::date($client->lastActivityAt),
            ]));
            $rows++;
        }

        $writer->close();

        return $rows;
    }
}
