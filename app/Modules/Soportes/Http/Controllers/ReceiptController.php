<?php

namespace App\Modules\Soportes\Http\Controllers;

use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One receipt and its files. Both routes go through ReceiptPolicy::view
 * (route middleware can:view,soporte): a reviewer of the receipt's company,
 * or the advisor who uploaded it or owns its client.
 */
final readonly class ReceiptController
{
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
        $file = $soporte->files()->whereKey($archivo)->first();
        abort_if($file === null, 404);

        $disk = Storage::disk((string) config('soportes.disk'));

        if (! $disk->exists($file->path)) {
            Log::warning('A payment receipt file is missing on the disk.', ['receipt' => $soporte->id, 'file' => $file->id, 'path' => $file->path]);

            abort(404);
        }

        return $disk->download($file->path, self::downloadName($file->original_name, $file->path), [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
