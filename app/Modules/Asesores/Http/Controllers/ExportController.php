<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Export\PortfolioWorkbook;
use App\Modules\Asesores\Application\Export\Workbook;
use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Excel files. Both read through CarteraRepository, like the screens: a
 * file holds the clients the user may see and nothing else, whatever the URL
 * says. Each download is audited.
 */
final readonly class ExportController
{
    private const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private CarteraRepository $cartera,
        private PortfolioWorkbook $workbooks,
        private AuditLogger $audit,
    ) {}

    /**
     * GET /asesores/clientes/exportar: the clients of the list, with the same
     * filters and order, and their documents.
     */
    public function portfolio(Request $request): BinaryFileResponse
    {
        $filter = PortfolioFilter::fromQuery($request->query());
        $workbook = $this->workbooks->ofClients($filter->clientQuery());

        $this->audit->record('asesores.portfolio.exported', 'portfolio', null, [], [
            'clients' => $workbook->clients,
            'invoices' => $workbook->invoices,
            'filters' => $filter->params(),
        ]);

        return $this->download($workbook, __('asesores::exportar.archivo.cartera'));
    }

    /**
     * GET /asesores/clientes/{client}/exportar: one client and their documents.
     */
    public function client(int $client): BinaryFileResponse
    {
        $detail = $this->cartera->client($client) ?? abort(404);

        Gate::authorize('view', $detail);

        $workbook = $this->workbooks->ofClient($detail);

        $this->audit->record('asesores.client.exported', 'client', $client, [], [
            'invoices' => $workbook->invoices,
        ]);

        return $this->download($workbook, __('asesores::exportar.archivo.cliente', ['codigo' => $this->safe($detail->summary->code)]));
    }

    private function download(Workbook $workbook, string $name): BinaryFileResponse
    {
        $file = $name.'-'.CarbonImmutable::today()->format('Y-m-d').'.xlsx';

        return response()
            ->download($workbook->path, $file, ['Content-Type' => self::XLSX])
            ->deleteFileAfterSend(true);
    }

    /**
     * A SAP code as part of a file name.
     */
    private function safe(string $code): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $code);
    }
}
