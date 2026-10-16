<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Cartera\Infrastructure\Export\ClientListSpreadsheet;
use App\Modules\Cartera\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Core\Contracts\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /cartera/clientes/exportar (cartera.clientes.exportar): the client list
 * as an Excel file, with the search, filters and order of the list page but
 * not its paging: every client that matches. The export is audited because
 * it carries the portfolio out of the application.
 */
final class ClientListExportController
{
    public function __invoke(Request $request, ClientListSpreadsheet $spreadsheet, AuditLogger $audit): BinaryFileResponse
    {
        $today = CarbonImmutable::today();
        $filter = ClientListFilter::fromInput($request->query());
        $rows = 0;

        $download = SpreadsheetDownload::make(
            __('cartera::exportar.lista.archivo').'-'.$today->toDateString().'.xlsx',
            function (string $path) use ($spreadsheet, $filter, $today, &$rows): void {
                $rows = $spreadsheet->write($path, $filter->toQuery($today, (int) config('cartera.sin_gestion_dias')));
            },
        );

        $audit->record('cartera.client_list.exported', 'client_list', null, [], ['rows' => $rows, 'filters' => $filter->query()]);

        return $download;
    }
}
