<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Models\User;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The reports of closed settlement versions, stored once and never changed
 * (§8.2, §10):
 *
 * - a report is generated only from a closed version, rendered, written to
 *   the documents disk under <directory>/<periodo>/v<N>/<archivo> and
 *   recorded in comisiones_documentos with its sha256, size and author;
 * - a report that already exists is never generated again: generating it
 *   returns the stored one;
 * - reading a report checks the file against its sha256 and refuses (and
 *   logs) one that changed or is gone;
 * - every generation and download is audited as comisiones.report.generated
 *   and comisiones.report.downloaded (context: version, referencia, sha256).
 *
 * The generation of one version is serialized by locking its settlement
 * row, so two users generating at once never write the same file twice.
 */
final class ReportArchive
{
    public const string COLLECTION = 'recaudo';

    public const string PAYROLL = 'nomina';

    private ?BrandPalette $palette = null;

    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly PdfRenderer $renderer,
        private readonly CurrentCompany $companies,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The stored reports of a version, keyed "tipo:referencia".
     *
     * @return array<string, DocumentRecord>
     */
    public function stored(Settlement $settlement): array
    {
        $record = $this->settlements->record($settlement->period, $settlement->version);

        if ($record === null) {
            return [];
        }

        return DocumentRecord::query()
            ->with('generator')
            ->where('liquidacion_id', $record->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (DocumentRecord $document): string => self::key($document->tipo, $document->referencia))
            ->all();
    }

    /** "recaudo:14": the key of stored(). */
    public static function key(string $type, string $reference): string
    {
        return "{$type}:{$reference}";
    }

    /**
     * The stored collection report, generated and stored first when missing.
     *
     * @throws ReportsUnavailable when the version is not closed.
     * @throws ReportMismatch when the report total differs from the consolidated figure (§9.3).
     */
    public function collection(Settlement $settlement, CollectionReport $report, ?User $user): DocumentRecord
    {
        self::assertClosed($settlement);

        if (! $report->matches()) {
            throw new ReportMismatch($report);
        }

        return $this->store($settlement, self::COLLECTION, $report->reference, $report->fileName(), $user, fn (): string => $this->renderer->render(
            'comisiones::pdf.recaudo',
            ['report' => $report, 'palette' => $this->palette(), 'generatedAt' => CarbonImmutable::now()],
        ));
    }

    /**
     * Generates every collection report of the version not stored yet. A
     * report whose total does not match is left out and returned, so the
     * others are still generated.
     *
     * @param  list<CollectionReport>  $reports
     *
     * @throws ReportsUnavailable when the version is not closed.
     */
    public function generateMissing(Settlement $settlement, array $reports, ?User $user): GeneratedReports
    {
        self::assertClosed($settlement);

        $stored = $this->stored($settlement);
        $generated = [];
        $mismatched = [];

        foreach ($reports as $report) {
            if (isset($stored[self::key(self::COLLECTION, $report->reference)])) {
                continue;
            }

            if (! $report->matches()) {
                $mismatched[] = $report;

                continue;
            }

            $generated[] = $this->collection($settlement, $report, $user);
        }

        return new GeneratedReports($generated, $mismatched);
    }

    /**
     * Stores a document of the version once: the existing one when it is
     * already stored, else the bytes $render gives.
     *
     * @param  callable(): string  $render
     *
     * @throws ReportsUnavailable when the version is not closed.
     */
    public function store(Settlement $settlement, string $type, string $reference, string $fileName, ?User $user, callable $render): DocumentRecord
    {
        self::assertClosed($settlement);
        $record = $this->settlementRecord($settlement);

        return DB::transaction(function () use ($settlement, $record, $type, $reference, $fileName, $user, $render): DocumentRecord {
            SettlementRecord::query()->whereKey($record->id)->lockForUpdate()->first();

            $existing = DocumentRecord::query()
                ->where('liquidacion_id', $record->id)
                ->where('tipo', $type)
                ->where('referencia', $reference)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $bytes = $render();
            $path = sprintf('%s/%s/v%d/%s', trim((string) config('comisiones.documents_directory'), '/'), $settlement->period->key(), $settlement->version, $fileName);

            if (! $this->disk()->put($path, $bytes)) {
                throw new RuntimeException("The commission report {$path} could not be written.");
            }

            $document = DocumentRecord::query()->create([
                'liquidacion_id' => $record->id,
                'tipo' => $type,
                'referencia' => $reference,
                'archivo' => $fileName,
                'ruta' => $path,
                'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes),
                'generado_por' => $user?->id,
            ]);

            $this->record('comisiones.report.generated', $settlement, $document, $user);

            return $document;
        });
    }

    /**
     * The bytes of a stored document, checked against its sha256, and the
     * download audited.
     *
     * @throws DocumentTampered when the file changed or is missing.
     */
    public function download(Settlement $settlement, DocumentRecord $document, ?User $user): string
    {
        $bytes = $this->read($document);
        $this->record('comisiones.report.downloaded', $settlement, $document, $user);

        return $bytes;
    }

    /**
     * Every given document in one ZIP written to a temporary file, each one
     * checked first; the download is audited once, with every document.
     *
     * @param  list<DocumentRecord>  $documents
     * @return string The path of the temporary ZIP; the caller deletes it.
     *
     * @throws DocumentTampered when a file changed or is missing.
     */
    public function zip(Settlement $settlement, array $documents, string $fileName, ?User $user): string
    {
        $contents = [];

        foreach ($documents as $document) {
            $contents[$document->archivo] = $this->read($document);
        }

        $path = tempnam(sys_get_temp_dir(), 'comisiones-zip-');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The server could not create a temporary ZIP of the commission reports.');
        }

        foreach ($contents as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        $this->audit->record(
            event: 'comisiones.report.downloaded',
            subjectType: 'liquidacion',
            subjectId: $this->settlementRecord($settlement)->id,
            context: [
                'version' => $settlement->version,
                'referencia' => 'zip',
                'archivo' => $fileName,
                'documentos' => array_map(fn (DocumentRecord $document): array => [
                    'referencia' => $document->referencia,
                    'sha256' => $document->sha256,
                ], $documents),
            ],
            period: $settlement->period->key(),
            user: $user,
        );

        return $path;
    }

    /**
     * @throws DocumentTampered when the file changed or is missing.
     */
    private function read(DocumentRecord $document): string
    {
        $disk = $this->disk();
        $bytes = $disk->exists($document->ruta) ? $disk->get($document->ruta) : null;

        if ($bytes === null) {
            $this->refuse($document, 'is missing from the documents disk');
        }

        if (! hash_equals($document->sha256, hash('sha256', $bytes))) {
            $this->refuse($document, 'no longer matches its sha256');
        }

        return $bytes;
    }

    /**
     * @throws DocumentTampered always.
     */
    private function refuse(DocumentRecord $document, string $reason): never
    {
        $exception = new DocumentTampered($document, $reason);
        Log::error($exception->getMessage(), ['documento' => $document->id, 'liquidacion' => $document->liquidacion_id]);

        throw $exception;
    }

    private function record(string $event, Settlement $settlement, DocumentRecord $document, ?User $user): void
    {
        $this->audit->record(
            event: $event,
            subjectType: 'documento',
            subjectId: $document->id,
            context: [
                'version' => $settlement->version,
                'tipo' => $document->tipo,
                'referencia' => $document->referencia,
                'archivo' => $document->archivo,
                'sha256' => $document->sha256,
            ],
            period: $settlement->period->key(),
            user: $user,
        );
    }

    private function settlementRecord(Settlement $settlement): SettlementRecord
    {
        return $this->settlements->record($settlement->period, $settlement->version)
            ?? throw new RuntimeException("Version {$settlement->version} of {$settlement->period->key()} is not stored.");
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('comisiones.documents_disk'));
    }

    private function palette(): BrandPalette
    {
        return $this->palette ??= $this->companies->of(CompanyCode::SGX)->palette;
    }

    /**
     * @throws ReportsUnavailable when the version is not closed.
     */
    private static function assertClosed(Settlement $settlement): void
    {
        if ($settlement->status !== SettlementStatus::Closed) {
            throw ReportsUnavailable::notClosed($settlement);
        }
    }
}
