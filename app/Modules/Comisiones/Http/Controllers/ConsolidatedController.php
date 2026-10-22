<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Export\ConsolidatedSpreadsheet;
use App\Modules\Comisiones\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedPdf;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedReport;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The consolidated report of a period (§7.6, §8.1), comisiones.periodos.consolidado*:
 *
 * - GET …/consolidado?version=N: on screen, every code linking to its settlement;
 * - GET …/consolidado.xlsx and …/consolidado.pdf: the same report as an Excel
 *   workbook and as a letter landscape PDF, audited as
 *   comisiones.export.downloaded.
 *
 * Any version can be seen and downloaded; one that is not closed says so
 * («Borrador», «Aprobada (sin cerrar)»). A period never calculated answers 404.
 */
final class ConsolidatedController
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly AuditTrail $audit,
    ) {}

    public function show(Request $request, string $periodo): View|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $selected = SelectedSettlement::calculated($request, $period, $this->settlements);

        if ($selected === null) {
            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        return view('comisiones::periodos.consolidado', [
            'period' => $period,
            'selected' => $selected,
            'report' => ConsolidatedReport::of($selected->settlement),
        ]);
    }

    public function spreadsheet(Request $request, ConsolidatedSpreadsheet $spreadsheet, string $periodo): BinaryFileResponse|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $selected = SelectedSettlement::calculated($request, $period, $this->settlements);

        if ($selected === null) {
            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        $report = ConsolidatedReport::of($selected->settlement);
        $download = SpreadsheetDownload::make($report->fileName('xlsx'), fn (string $path) => $spreadsheet->write($path, $report));
        $this->record($request, $report, 'xlsx');

        return $download;
    }

    public function pdf(Request $request, ConsolidatedPdf $renderer, string $periodo): Response|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $selected = SelectedSettlement::calculated($request, $period, $this->settlements);

        if ($selected === null) {
            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        $report = ConsolidatedReport::of($selected->settlement);
        $user = $request->user();
        $pdf = $renderer->render($report, $user instanceof User ? $user->name : null);
        $this->record($request, $report, 'pdf');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->fileName('pdf').'"',
        ]);
    }

    private function record(Request $request, ConsolidatedReport $report, string $format): void
    {
        $user = $request->user();
        $settlement = $report->settlement;

        $this->audit->record(
            event: 'comisiones.export.downloaded',
            subjectType: 'liquidacion',
            subjectId: $this->settlements->record($settlement->period, $settlement->version)?->id,
            context: [
                'informe' => 'consolidado',
                'formato' => $format,
                'version' => $settlement->version,
                'revision' => $settlement->revision,
                'estado' => $settlement->status->value,
                'archivo' => $report->fileName($format),
            ],
            period: $settlement->period->key(),
            user: $user instanceof User ? $user : null,
        );
    }
}
