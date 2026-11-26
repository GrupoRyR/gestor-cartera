<?php

namespace App\Modules\Cobro\Infrastructure\Export;

use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Reports\MailReport;
use Carbon\CarbonImmutable;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The collection report as an Excel workbook: one row per item of the
 * filter, every one and not a page, newest run first. Amounts, invoices and
 * hours are numbers, so the analyst can filter and add them up.
 *
 * It reads the items in chunks (MailReport::allItems()) and writes them as
 * it goes, and it asks Core for the advisors once per company, so a large
 * report neither has to fit in memory nor costs a call per client.
 */
final class MailReportSpreadsheet
{
    /**
     * Keys of the columns under cobro::informe.exportar.columnas, in order.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'fecha', 'empresa', 'tipo', 'codigo', 'cliente', 'nit', 'asesor', 'estado', 'monto',
        'facturas', 'enviado', 'respuesta', 'horas', 'detalle',
    ];

    public function __construct(private readonly MailReport $report) {}

    /**
     * Writes the workbook to $path.
     *
     * @return int How many items it holds.
     */
    public function write(string $path, MailReportFilter $filter): int
    {
        $advisors = $this->report->advisorNamesByClient($filter);

        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(__('cobro::informe.exportar.hoja'));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(10, 2);
        $sheet->setColumnWidth(18, 3);
        $sheet->setColumnWidth(14, 4);
        $sheet->setColumnWidth(42, 5);
        $sheet->setColumnWidth(14, 6);
        $sheet->setColumnWidth(26, 7);
        $sheet->setColumnWidth(18, 8, 9);
        $sheet->setColumnWidth(10, 10);
        $sheet->setColumnWidth(18, 11, 12, 13);
        $sheet->setColumnWidth(50, 14);

        $writer->addRow(Cells::header(array_map(fn (string $column): string => __('cobro::informe.exportar.columnas.'.$column), self::COLUMNS)));

        $rows = 0;

        foreach ($this->report->allItems($filter) as $item) {
            $writer->addRow(Cells::row([
                Cells::date($item->run->scheduled_for),
                Cells::text($item->run->company->value),
                Cells::text($item->run->kind->label()),
                Cells::text($item->client_code),
                Cells::text($item->client_name),
                Cells::text($item->tax_id),
                Cells::text($advisors[$item->client_id] ?? null),
                Cells::text($item->status->label()),
                Cells::money((float) $item->amount),
                Cells::integer($item->invoice_count),
                Cells::dateTime($item->sent_at),
                Cells::dateTime(self::firstReply($item)),
                Cells::decimal(self::hoursToFirstReply($item)),
                Cells::text(self::detail($item)),
            ]));
            $rows++;
        }

        $writer->close();

        return $rows;
    }

    /**
     * When the item got its first reply: the first_reply_at column that
     * MailReport::allItems() adds, cast to a date; null without replies.
     */
    private static function firstReply(RunItem $item): ?CarbonImmutable
    {
        $reply = $item->getAttribute('first_reply_at');

        return $reply instanceof CarbonImmutable ? $reply : null;
    }

    private static function hoursToFirstReply(RunItem $item): ?float
    {
        $reply = self::firstReply($item);

        if ($item->sent_at === null || $reply === null) {
            return null;
        }

        return round(max(0, $item->sent_at->diffInSeconds($reply)) / 3600, 2);
    }

    /**
     * Why the item did not reach the client, worded as the delivery report.
     */
    private static function detail(RunItem $item): ?string
    {
        return match ($item->status) {
            ItemStatus::Bounced => $item->bounced_recipient === null
                ? __('cobro::lotes.detalle.rebote_sin_correo', ['motivo' => $item->bounce_reason ?? '—'])
                : __('cobro::lotes.detalle.rebote', ['correo' => $item->bounced_recipient, 'motivo' => $item->bounce_reason ?? '—']),
            ItemStatus::Failed => $item->error,
            ItemStatus::Excluded => $item->exclusion_reason,
            default => null,
        };
    }
}
