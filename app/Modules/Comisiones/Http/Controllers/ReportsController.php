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
 * - POST …/informes/generar: stores every report and the payroll file
 *   (§8.1 annex) not stored yet;
 * - GET …/informes/{referencia}.pdf: one stored report;
 * - GET …/informes.zip: every report of the version in one ZIP;
 * - GET …/informes/nomina.xlsx: the stored payroll file.
 *
 * Only the POST generates: a download only reads, and sends back to the list
 * when what it asks for is not stored yet. A stored report is never
 * generated again; a download checks its sha256.
 */
final class ReportsController
{
    /** The time a batch of collection reports may take (§11: under 2 minutes). */
    private const int BATCH_SECONDS = 180;

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

        $user = self::user($request);
        self::allowBatchTime();
        $outcome = $this->archive->generateMissing($settlement, $this->reports->of($settlement), $user);
        $payroll = $this->archive->payroll($settlement, $user);
        $back = $this->back($request, $settlement);
        $count = count($outcome->generated) + ($payroll->wasRecentlyCreated ? 1 : 0);

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
        $document = $this->archive->find($settlement, ReportArchive::COLLECTION, $report->reference);

        if ($document === null) {
            return $this->back($request, $settlement)->with('error', self::missingMessage([$report]));
        }

        try {
            $bytes = $this->archive->download($settlement, $document, self::user($request));
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

        $stored = $this->archive->stored($settlement);
        $documents = [];
        $missing = [];

        foreach ($this->reports->of($settlement) as $report) {
            $document = $stored[ReportArchive::key(ReportArchive::COLLECTION, $report->reference)] ?? null;

            if ($document === null) {
                $missing[] = $report;
            } else {
                $documents[] = $document;
            }
        }

        if ($missing !== []) {
            return $this->back($request, $settlement)->with('error', self::missingMessage($missing));
        }

        $fileName = sprintf('Informes_Recaudo_%s_v%d.zip', $settlement->period->key(), $settlement->version);

        // Reading and zipping thirty stored PDFs can still take a while.
        self::allowBatchTime();

        try {
            $path = $this->archive->zip($settlement, $documents, $fileName, self::user($request));
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

        $document = $this->archive->find($settlement, ReportArchive::PAYROLL, ReportArchive::PAYROLL);

        if ($document === null) {
            return $this->back($request, $settlement)->with('error', __('comisiones::informes.mensajes.sin_generar'));
        }

        try {
            $bytes = $this->archive->download($settlement, $document, self::user($request));
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
     * Why reports a download asked for are not stored: the ones whose total
     * does not match can never be generated (§9.3); the others wait for the
     * «Generar» button.
     *
     * @param  non-empty-list<CollectionReport>  $reports
     */
    private static function missingMessage(array $reports): string
    {
        $mismatched = array_values(array_filter($reports, fn (CollectionReport $report): bool => ! $report->matches()));

        return $mismatched !== [] ? self::mismatchMessage($mismatched) : __('comisiones::informes.mensajes.sin_generar');
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

    /**
     * A batch renders every collection PDF of the version in one request
     * (§11: all of them in under 2 minutes), longer than the 30 seconds
     * shared hosting usually allows. Should the host still stop it, nothing
     * is lost: each PDF is stored as soon as it is rendered, so the next
     * attempt only renders the missing ones.
     *
     * The limit is only ever raised: an unlimited one (0, as on the CLI and
     * in the tests) or a longer one is left as it is.
     */
    private static function allowBatchTime(): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < self::BATCH_SECONDS) {
            set_time_limit(self::BATCH_SECONDS);
        }
    }

    private static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
