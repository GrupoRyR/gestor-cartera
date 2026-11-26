<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Http\Requests\MailReportRequest;
use App\Modules\Cobro\Infrastructure\Export\MailReportSpreadsheet;
use App\Modules\Cobro\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Core\Contracts\AuditLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /cobro/informe/exportar (cobro.informe.export): the collection report
 * as an Excel file, with the filters of the page but not its paging: every
 * item that matches. The route checks cobro.export and limits it to 10 a
 * minute per user (cobro-exports, CobroServiceProvider). The export is
 * audited because it carries client data out of the application.
 */
final class MailReportExportController
{
    public function __invoke(MailReportRequest $request, MailReportSpreadsheet $spreadsheet, AuditLogger $audit): BinaryFileResponse
    {
        $filter = $request->filter();
        $rows = 0;

        $download = SpreadsheetDownload::make(
            __('cobro::informe.exportar.archivo', ['desde' => $filter->from->toDateString(), 'hasta' => $filter->to->toDateString()]).'.xlsx',
            function (string $path) use ($spreadsheet, $filter, &$rows): void {
                $rows = $spreadsheet->write($path, $filter);
            },
        );

        $audit->record('cobro.mail_report.exported', 'cobro_mail_report', null, [], [
            'rows' => $rows,
            'filters' => array_filter($request->validated(), fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        return $download;
    }
}
