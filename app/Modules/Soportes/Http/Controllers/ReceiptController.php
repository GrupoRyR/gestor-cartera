<?php

namespace App\Modules\Soportes\Http\Controllers;

use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Modules\Soportes\Infrastructure\Models\ReceiptFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One receipt and its files. Every route goes through ReceiptPolicy::view
 * (route middleware can:view,soporte): a reviewer of the receipt's company,
 * or the advisor who uploaded it or owns its client.
 */
final readonly class ReceiptController
{
    /**
     * The stored types the browser may show inline. Upload only accepts
     * these, sniffed from the bytes; the list guards the inline route anyway.
     */
    private const array INLINE = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * GET /soportes/{soporte} (soportes.show): the payment data, its status
     * and review, the invoices it covers and its files.
     */
    public function show(Receipt $soporte): View
    {
        $soporte->load([
            'invoices' => fn (HasMany $query) => $query->orderBy('due_at')->orderBy('document_number'),
            'files',
            'uploader:id,name',
            'reviewer:id,name',
        ]);

        return view('soportes::detalle.show', ['receipt' => $soporte]);
    }

    /**
     * GET /soportes/{soporte}/archivos/{archivo} (soportes.files.download):
     * the file streamed from the private disk as an attachment, with the
     * name it was uploaded with and its sniffed type. A file of another
     * receipt answers 404, as does a file missing on the disk (logged).
     */
    public function download(Receipt $soporte, int $archivo): StreamedResponse
    {
        [$file, $disk] = $this->stored($soporte, $archivo);

        return $disk->download($file->path, self::downloadName($file->original_name, $file->path), [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /soportes/{soporte}/archivos/{archivo}/ver (soportes.files.show):
     * the file shown by the browser itself (an <img> on the detail, or a PDF
     * in another tab), only for the types of self::INLINE; any other stored
     * type answers 404, so nothing else (SVG, HTML) ever renders inline. The
     * same 404s as the download apply. Never cached: it is a financial
     * document.
     */
    public function inline(Receipt $soporte, int $archivo): StreamedResponse
    {
        [$file, $disk] = $this->stored($soporte, $archivo);
        abort_unless(in_array($file->mime, self::INLINE, true), 404);

        return $disk->response($file->path, self::downloadName($file->original_name, $file->path), [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /**
     * The receipt's file $id and its disk; 404 when the file belongs to
     * another receipt or is missing on the disk (logged).
     *
     * @return array{ReceiptFile, FilesystemAdapter}
     */
    private function stored(Receipt $receipt, int $id): array
    {
        $file = $receipt->files()->whereKey($id)->first();
        abort_if($file === null, 404);

        $disk = Storage::disk((string) config('soportes.disk'));

        if (! $disk->exists($file->path)) {
            Log::warning('A payment receipt file is missing on the disk.', ['receipt' => $receipt->id, 'file' => $file->id, 'path' => $file->path]);

            abort(404);
        }

        return [$file, $disk];
    }

    /**
     * The uploaded name without path separators, which a Content-Disposition
     * cannot carry; the stored name when nothing is left.
     */
    private static function downloadName(string $originalName, string $path): string
    {
        $name = trim(str_replace(['/', '\\'], '-', $originalName));

        return $name === '' ? basename($path) : $name;
    }
}
