<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\Manual\ManualCarteraUpload;
use App\Modules\Ingesta\Application\Manual\ManualPreview;
use App\Modules\Ingesta\Application\Manual\UnreadableWorkbook;
use App\Modules\Ingesta\Http\Requests\ManualUploadRequest;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The manual fallback for the cartera (only that dataset): upload the SAP
 * Excel with its company and cutoff date, look at the preview, then
 * confirm or discard.
 *
 * Between preview and confirm the file waits on the local disk under
 * ingesta/manual/{token}.xlsx and the session keeps, under the token, its
 * path, company, cutoff, SHA-256 and uploader. Confirm reads the file
 * again, checks the hash and the user, and hands the batch to IngestaSink;
 * confirm and discard then delete the file and the session entry.
 */
final class ManualUploadController
{
    private const string SESSION = 'ingesta_manual';

    /** Disk and directory of the uploads waiting for confirm or discard; ingesta:limpiar-cargas-manuales prunes them too. */
    public const string DISK = 'local';

    public const string DIRECTORY = 'ingesta/manual';

    public function __construct(
        private readonly ManualCarteraUpload $upload,
        private readonly IngestaSink $sink,
    ) {}

    public function create(): View
    {
        return view('ingesta::manual.create', [
            'companies' => array_combine(
                array_map(fn (CompanyCode $code): string => $code->value, ManualUploadRequest::companies()),
                array_map(fn (CompanyCode $code): string => $code->label(), ManualUploadRequest::companies()),
            ),
            'today' => CarbonImmutable::now()->format('Y-m-d'),
        ]);
    }

    public function preview(ManualUploadRequest $request): View|RedirectResponse
    {
        $token = (string) Str::uuid();
        $path = self::DIRECTORY."/{$token}.xlsx";
        $this->disk()->putFileAs(self::DIRECTORY, $request->workbook(), "{$token}.xlsx");

        try {
            $preview = $this->upload->prepare($this->disk()->path($path), $request->company(), $request->cutoff(), $this->userId($request));
        } catch (UnreadableWorkbook $e) {
            $this->disk()->delete($path);

            return redirect()->route('ingesta.manual.create')
                ->withErrors(['archivo' => $e->getMessage()])
                ->withInput($request->except('archivo'));
        }

        $request->session()->put(self::SESSION.".{$token}", [
            'path' => $path,
            'company' => $request->company()->value,
            'cutoff' => $request->cutoff()->format('Y-m-d'),
            'sha256' => hash_file('sha256', $this->disk()->path($path)),
            'user_id' => $this->userId($request),
        ]);

        return view('ingesta::manual.preview', ['preview' => $preview, 'token' => $token]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $entry = $this->entry($request, $token);
        $absolute = $this->disk()->path($entry['path']);

        if (! $this->disk()->exists($entry['path']) || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $absolute))) {
            $this->forget($request, $token, $entry['path']);

            return redirect()->route('ingesta.manual.create')->with('error', __('ingesta::manual.errores.vencida'));
        }

        $company = CompanyCode::from($entry['company']);
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $entry['cutoff'], 'America/Bogota') ?: CarbonImmutable::now()->startOfDay();

        try {
            $preview = $this->upload->prepare($absolute, $company, $cutoff, $this->userId($request));
            $duplicate = IngestaLoad::query()->where('accepted_batch_id', $preview->batch->batchId)->exists();
            $load = $this->sink->receive($preview->batch);
        } catch (UnreadableWorkbook|InvalidBatch $e) {
            $this->forget($request, $token, $entry['path']);

            return redirect()->route('ingesta.index')->with('error', __('ingesta::manual.resultado.rechazada', ['error' => $e->getMessage()]));
        }

        $this->forget($request, $token, $entry['path']);

        if ($duplicate) {
            return redirect()->route('ingesta.index')->with('status', __('ingesta::manual.resultado.repetida'));
        }

        $this->audit($preview, $load->id);

        return redirect()->route('ingesta.index')->with('status', __('ingesta::manual.resultado.cargada', [
            'empresa' => $company->label(),
            'fecha' => DateFormat::date($cutoff),
            'filas' => number_format($load->rowCount, 0, ',', '.'),
        ]));
    }

    public function discard(Request $request, string $token): RedirectResponse
    {
        $entry = $this->entry($request, $token);
        $this->forget($request, $token, $entry['path']);

        return redirect()->route('ingesta.index')->with('status', __('ingesta::manual.resultado.descartada'));
    }

    /**
     * The session entry of the token; 404 when there is none or another
     * user made it.
     *
     * @return array{path: string, company: string, cutoff: string, sha256: string, user_id: int|null}
     */
    private function entry(Request $request, string $token): array
    {
        $entry = $request->session()->get(self::SESSION.".{$token}");

        abort_unless(is_array($entry) && ($entry['user_id'] ?? null) === $this->userId($request), 404);

        /** @var array{path: string, company: string, cutoff: string, sha256: string, user_id: int|null} $entry */
        return $entry;
    }

    private function forget(Request $request, string $token, string $path): void
    {
        $this->disk()->delete($path);
        $request->session()->forget(self::SESSION.".{$token}");
    }

    private function audit(ManualPreview $preview, int $loadId): void
    {
        if (! app()->bound(AuditLogger::class)) {
            return;
        }

        app(AuditLogger::class)->record('ingesta.load.uploaded', 'ingesta_load', $loadId, [], [
            'company' => $preview->batch->company->value,
            'cutoff_date' => $preview->batch->cutoffDate->format('Y-m-d'),
            'rows' => $preview->rowCount(),
        ]);
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }
}
