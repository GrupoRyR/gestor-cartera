<?php

namespace App\Modules\Cartera\Infrastructure\Export;

use App\Modules\Cartera\Application\Clients\ClientFile;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * One client as an Excel workbook: its data, open invoices, contacts,
 * payment commitments and log, each in its own sheet. It holds what the
 * client file shows, so the analyst can take it to a call or send it on.
 */
final class ClientFileSpreadsheet
{
    public function write(string $path, ClientFile $file): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $this->data($writer, $file);
        $this->invoices($writer, $file);
        $this->contacts($writer, $file);
        $this->commitments($writer, $file);
        $this->log($writer, $file);

        $writer->close();
    }

    private function data(Writer $writer, ClientFile $file): void
    {
        $detail = $file->detail;
        $client = $detail->summary;

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(__('cartera::exportar.ficha.datos.hoja'));
        $sheet->setColumnWidth(26, 1);
        $sheet->setColumnWidth(44, 2);

        $bold = (new Style)->withFontBold(true);
        $label = fn (string $key): StringCell => new StringCell(__('cartera::exportar.ficha.datos.'.$key), $bold);

        $rows = [
            ['cliente', Cells::text($client->name)],
            ['empresa', Cells::text($client->company->label())],
            ['codigo', Cells::text($client->code)],
            ['nit', Cells::text($client->taxId)],
            ['estado', Cells::text($client->status->label())],
            ['grupo', Cells::text($detail->group)],
            ['ciudad', Cells::text($detail->city)],
            ['condicion', Cells::text($detail->paymentTerms)],
            ['cupo', Cells::money($detail->creditLimit)],
            ['total', Cells::money($client->balance)],
            ['vencido', Cells::money($client->overdue)],
            ['dias', Cells::integer($client->maxDaysOverdue)],
            ['asesor', Cells::text($client->advisor?->name)],
            ['gestor', Cells::text($client->manager?->name)],
        ];

        foreach ($rows as [$key, $value]) {
            $writer->addRow(Cells::row([$label($key), $value]));
        }
    }

    private function invoices(Writer $writer, ClientFile $file): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(__('cartera::exportar.ficha.facturas.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(22, 1);
        $sheet->setColumnWidth(14, 2, 3, 4);
        $sheet->setColumnWidth(18, 5, 6);

        $writer->addRow(Cells::header($this->labels('facturas', ['documento', 'emision', 'vence', 'dias', 'valor', 'saldo'])));

        foreach ($file->invoices as $invoice) {
            $writer->addRow(Cells::row([
                Cells::text("{$invoice->documentType} {$invoice->documentNumber}"),
                Cells::date($invoice->issuedAt),
                Cells::date($invoice->dueAt),
                Cells::integer($invoice->daysOverdue),
                Cells::money($invoice->amount),
                Cells::money($invoice->balance),
            ]));
        }
    }

    private function contacts(Writer $writer, ClientFile $file): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(__('cartera::exportar.ficha.contactos.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(30, 1);
        $sheet->setColumnWidth(24, 2);
        $sheet->setColumnWidth(34, 3);
        $sheet->setColumnWidth(18, 4);
        $sheet->setColumnWidth(12, 5);

        $writer->addRow(Cells::header($this->labels('contactos', ['nombre', 'cargo', 'correo', 'telefono', 'principal'])));

        foreach ($file->detail->contacts as $contact) {
            $writer->addRow(Cells::row([
                Cells::text($contact->name),
                Cells::text($contact->position),
                Cells::text($contact->email),
                Cells::text($contact->phone),
                Cells::text($contact->isPrimary ? __('cartera::exportar.ficha.si') : ''),
            ]));
        }
    }

    private function commitments(Writer $writer, ClientFile $file): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(__('cartera::exportar.ficha.compromisos.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(14, 1);
        $sheet->setColumnWidth(18, 2);
        $sheet->setColumnWidth(14, 3);
        $sheet->setColumnWidth(26, 4);
        $sheet->setColumnWidth(50, 5);

        $writer->addRow(Cells::header($this->labels('compromisos', ['vence', 'valor', 'estado', 'registrado_por', 'notas'])));

        foreach ($file->commitments as $commitment) {
            $writer->addRow(Cells::row([
                Cells::date($commitment->dueDate),
                Cells::money($commitment->amount),
                Cells::text($commitment->status->label()),
                Cells::text($commitment->createdBy->name),
                Cells::text($commitment->notes),
            ]));
        }
    }

    private function log(Writer $writer, ClientFile $file): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(__('cartera::exportar.ficha.bitacora.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(18, 1);
        $sheet->setColumnWidth(20, 2);
        $sheet->setColumnWidth(40, 3);
        $sheet->setColumnWidth(30, 4);
        $sheet->setColumnWidth(60, 5);
        $sheet->setColumnWidth(24, 6);

        $writer->addRow(Cells::header($this->labels('bitacora', ['fecha', 'tipo', 'resumen', 'resultado', 'detalle', 'usuario'])));

        foreach ($file->activities as $activity) {
            $writer->addRow(Cells::row([
                Cells::dateTime($activity->occurredAt),
                Cells::text($activity->type->label()),
                Cells::text($activity->summary),
                Cells::text($activity->result),
                Cells::text($activity->details),
                Cells::text($activity->user->name ?? __('cartera::exportar.ficha.sistema')),
            ]));
        }
    }

    /**
     * The header labels of a sheet.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function labels(string $sheet, array $keys): array
    {
        return array_map(fn (string $key): string => __("cartera::exportar.ficha.{$sheet}.columnas.{$key}"), $keys);
    }
}
