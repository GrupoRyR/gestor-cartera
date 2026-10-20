<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReport;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReports;
use App\Modules\Comisiones\Infrastructure\Report\DocumentTampered;
use App\Modules\Comisiones\Infrastructure\Report\ReportArchive;
use App\Modules\Comisiones\Infrastructure\Report\ReportMismatch;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Support\Formatting\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports of a closed settlement version (§7.7, §8.2, §10),
 * comisiones.periodos.informes.*, all under comisiones.reports:
 *
 * - GET …/informes?version=N: one row per collection report (codes with
 *   collections and 85.1) with its figures, its §9.3 check against the
 *   consolidated report and its stored file; an explanation when the
 *   version is not closed;
 * - POST …/informes/generar: stores every report not stored yet;
 * - GET …/informes/{referencia}.pdf: one stored report, generated first
 *   when missing;
 * - GET …/informes.zip: every report of the version in one ZIP;
 * - GET …/informes/nomina.xlsx: the payroll file (§8.1 annex), stored on
 *   its first download and served from the stored copy after that.
 *
 * A stored report is never generated again; a download checks its sha256.
 */
final class ReportsController
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly CollectionReports $reports,
        private readonly ReportArchive $archive,
    ) {}

    public function index(Request $request, string $periodo): View|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);

        try {
            $selected = SelectedSettlement::from($request, $period, $this->settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        $settlement = $selected->settlement;
        $closed = $settlement !== null && $settlement->status === SettlementStatus::Closed;
        $lastClosed = SettlementRecord::query()
            ->where('periodo', $period->key())
            ->where('estado', SettlementStatus::Closed->value)
            ->max('version');

        return view('comisiones::informes.index', [
            'period' => $period,
            'selected' => $selected,
            'closed' => $closed,
            'reports' => $closed ? $this->reports->of($settlement) : [],
            'stored' => $closed ? $this->archive->stored($settlement) : [],
            'lastClosed' => ! $closed && $lastClosed !== null ? (int) $lastClosed : null,
        ]);
    }

    public function generate(Request $request, string $periodo): RedirectResponse
    {
        $settlement = $this->closed($request, PeriodRoute::period($periodo));

        if (! $settlement instanceof Settlement) {
            return $settlement;
        }

        $outcome = $this->archive->generateMissing($settlement, $this->reports->of($settlement), self::user($request));
        $back = $this->back($request, $settlement);
        $count = count($outcome->generated);

        if ($outcome->mismatched !== []) {
            $back->with('error', self::mismatchMessage($outcome->mismatched));

            if ($count === 0) {
                return $back;
            }
        }

        return $back->with('status', $count > 0
            ? trans_choice('comisiones::informes.mensajes.generados', $count, ['cantidad' => $count])
            : __('comisiones::informes.mensajes.ya_generados'));
    }

    public function pdf(Request $request, string $periodo, string $referencia): StreamedResponse|RedirectResponse
    {
        $settlement = $this->closed($request, PeriodRoute::period($periodo));

        if (! $settlement instanceof Settlement) {
            return $settlement;
        }

        $report = $this->reports->find($settlement, $referencia);
        abort_if($report === null, 404);
        $user = self::user($request);

        try {
            $document = $this->archive->collection($settlement, $report, $user);
            $bytes = $this->archive->download($settlement, $document, $user);
        } catch (ReportMismatch $mismatch) {
            return $this->back($request, $settlement)->with('error', self::mismatchMessage([$mismatch->report]));
        } catch (DocumentTampered $tampered) {
            return $this->tampered($request, $settlement, $tampered->document);
        }

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $document->archivo, ['Content-Type' => 'application/pdf']);
    }

    public function zip(Request $request, string $periodo): BinaryFileResponse|RedirectResponse
    {
        $settlement = $this->closed($request, PeriodRoute::period($periodo));

        if (! $settlement instanceof Settlement) {
            return $settlement;
        }

        $user = self::user($request);
        $reports = $this->reports->of($settlement);
        $outcome = $this->archive->generateMissing($settlement, $reports, $user);

        if ($outcome->mismatched !== []) {
            return $this->back($request, $settlement)->with('error', self::mismatchMessage($outcome->mismatched));
        }

        $stored = $this->archive->stored($settlement);
        $documents = [];

        foreach ($reports as $report) {
            $documents[] = $stored[ReportArchive::key(ReportArchive::COLLECTION, $report->reference)];
        }

        $fileName = sprintf('Informes_Recaudo_%s_v%d.zip', $settlement->period->key(), $settlement->version);

        try {
            $path = $this->archive->zip($settlement, $documents, $fileName, $user);
        } catch (DocumentTampered $tampered) {
            return $this->tampered($request, $settlement, $tampered->document);
        }

        return response()->download($path, $fileName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function payroll(Request $request, string $periodo): StreamedResponse|RedirectResponse
    {
        $settlement = $this->closed($request, PeriodRoute::period($periodo));

        if (! $settlement instanceof Settlement) {
            return $settlement;
        }

        $user = self::user($request);

        try {
            $document = $this->archive->payroll($settlement, $user);
            $bytes = $this->archive->download($settlement, $document, $user);
        } catch (DocumentTampered $tampered) {
            return $this->tampered($request, $settlement, $tampered->document);
        }

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $document->archivo, ['Content-Type' => SpreadsheetDownload::MIME_TYPE]);
    }

    /**
     * The closed version asked for, or the way back to the list with the
     * reason when it is not closed. 404 for a period never calculated.
     */
    private function closed(Request $request, Period $period): Settlement|RedirectResponse
    {
        try {
            $selected = SelectedSettlement::from($request, $period, $this->settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        $settlement = $selected->settlement;
        abort_if($settlement === null, 404);

        if ($settlement->status !== SettlementStatus::Closed) {
            return $this->back($request, $settlement)->with('error', __('comisiones::informes.solo_cerrada'));
        }

        return $settlement;
    }

    private function back(Request $request, Settlement $settlement): RedirectResponse
    {
        $version = $request->query('version');

        return redirect()->route('comisiones.periodos.informes.index', array_filter([
            'periodo' => $settlement->period->key(),
            'version' => is_string($version) && $version !== '' ? $settlement->version : null,
        ]));
    }

    private function tampered(Request $request, Settlement $settlement, DocumentRecord $document): RedirectResponse
    {
        return $this->back($request, $settlement)->with('error', __('comisiones::informes.mensajes.alterado', ['referencia' => $document->referencia]));
    }

    /**
     * @param  list<CollectionReport>  $reports
     */
    private static function mismatchMessage(array $reports): string
    {
        $details = array_map(fn (CollectionReport $report): string => __('comisiones::informes.mensajes.diferencia', [
            'referencia' => $report->reference,
            'pdf' => Money::format($report->total()->amount()),
            'consolidado' => Money::format($report->expected->amount()),
        ]), $reports);

        return trans_choice('comisiones::informes.mensajes.no_cuadra', count($reports), [
            'referencias' => implode(', ', array_map(fn (CollectionReport $report): string => $report->reference, $reports)),
            'detalle' => implode('; ', $details),
        ]);
    }

    private static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
