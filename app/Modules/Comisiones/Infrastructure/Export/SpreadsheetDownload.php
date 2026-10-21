<?php

namespace App\Modules\Comisiones\Infrastructure\Export;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Turns a workbook writer into a download: the workbook is written to a
 * temporary file, which is deleted once the response has been sent.
 */
final class SpreadsheetDownload
{
    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  callable(string): mixed  $write  Writes the workbook to the path it is given.
     */
    public static function make(string $filename, callable $write): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'comisiones-');

        if ($path === false) {
            throw new RuntimeException('The server could not create a temporary file for the export.');
        }

        try {
            $write($path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return response()->download($path, $filename, ['Content-Type' => self::MIME_TYPE])->deleteFileAfterSend(true);
    }
}
