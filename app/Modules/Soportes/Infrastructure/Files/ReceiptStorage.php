<?php

namespace App\Modules\Soportes\Infrastructure\Files;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Domain\Exceptions\ReceiptRefused;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The receipt files on the private disk of config('soportes.disk'), at
 * <directory>/<company>/<yyyy>/<mm>/<uuid>.<ext>. The name is generated and
 * the extension follows the content type sniffed from the bytes, never the
 * name the user gave, so a file can only be stored as what it really is.
 */
final class ReceiptStorage
{
    /**
     * Extension of each content type a receipt file may have.
     */
    private const array EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * The sniffed content type of $file, when config('soportes.allowed_mimetypes')
     * accepts it.
     *
     * @throws ReceiptRefused When the content is not an accepted type.
     */
    public function acceptedType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $allowed = (array) config('soportes.allowed_mimetypes');

        if (! isset(self::EXTENSIONS[$mime]) || ! in_array($mime, $allowed, true)) {
            throw ReceiptRefused::fileNotAccepted($mime);
        }

        return $mime;
    }

    /**
     * Writes $file for a receipt of $company and returns its path on the disk.
     *
     * @throws ReceiptRefused When the content is not an accepted type.
     * @throws RuntimeException When the disk does not take the file.
     */
    public function store(UploadedFile $file, CompanyCode $company, CarbonImmutable $on): string
    {
        $extension = self::EXTENSIONS[$this->acceptedType($file)];
        $directory = implode('/', [trim((string) config('soportes.directory'), '/'), $company->value, $on->format('Y'), $on->format('m')]);

        $path = $this->disk()->putFileAs($directory, $file, Str::uuid()->toString().'.'.$extension);

        if ($path === false) {
            throw new RuntimeException('The receipt file could not be stored.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        if ($paths !== []) {
            $this->disk()->delete($paths);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('soportes.disk'));
    }
}
