<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The support files of the manual captures (§7.4), on the private disk
 * config('comisiones.supports_disk') under
 * config('comisiones.supports_directory')/<tipo>/. They are only served
 * through the screen, which checks the permission and audits the download.
 */
final class Supports
{
    /** The extensions a support may have. */
    public const array EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv'];

    /** Kilobytes: 10 MB. */
    public const int MAX_KB = 10240;

    /**
     * Stores the file under a random name and returns its path on the disk.
     * It keeps the extension the user gave, which SupportFile already
     * checked against the content.
     */
    public function store(UploadedFile $file, CaptureKind $kind): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $this->disk()->putFileAs(
            self::directory().'/'.$kind->value,
            $file,
            bin2hex(random_bytes(16)).($extension === '' ? '' : '.'.$extension),
        );

        if ($path === false) {
            throw new RuntimeException('The support file could not be stored.');
        }

        return $path;
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disk()->exists($path);
    }

    /**
     * Streams the file as a download named "soporte-<tipo>-<id>.<ext>", as
     * application/octet-stream so the browser never renders it; nosniff
     * comes from public/.htaccess.
     */
    public function download(string $path, CaptureKind $kind, int $id): StreamedResponse
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $this->disk()->download(
            $path,
            "soporte-{$kind->value}-{$id}".($extension === '' ? '' : '.'.$extension),
            ['Content-Type' => 'application/octet-stream'],
        );
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('comisiones.supports_disk'));
    }

    private static function directory(): string
    {
        return trim((string) config('comisiones.supports_directory'), '/');
    }
}
