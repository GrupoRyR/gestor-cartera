<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\LoadClientFile;
use App\Modules\Cartera\Infrastructure\Export\ClientFileSpreadsheet;
use App\Modules\Cartera\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Core\Contracts\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /cartera/clientes/{client}/exportar (cartera.clientes.exportar-ficha):
 * one client's file as an Excel workbook. A client that does not exist, or is
 * out of the user's reach, is a 404. The export is audited.
 */
final class ClientFileExportController
{
    public function __invoke(int $client, LoadClientFile $files, ClientFileSpreadsheet $spreadsheet, AuditLogger $audit): BinaryFileResponse
    {
        $file = $files->handle($client) ?? abort(404);
        $name = Str::slug($file->detail->summary->name) ?: (string) $client;

        $download = SpreadsheetDownload::make(
            __('cartera::exportar.ficha.archivo')."-{$name}-".CarbonImmutable::today()->toDateString().'.xlsx',
            fn (string $path) => $spreadsheet->write($path, $file),
        );

        $audit->record('cartera.client_file.exported', 'client', $client);

        return $download;
    }
}
