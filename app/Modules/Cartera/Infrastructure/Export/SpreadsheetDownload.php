<?php

namespace App\Modules\Cartera\Infrastructure\Export;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Turns a workbook writer into a download. The workbook goes to a temporary
 * file as it is written, so a large export does not live in memory, and the
 * file is deleted once the response has been sent.
 */
final class SpreadsheetDownload
{
    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  callable(string): mixed  $write  Writes the workbook to the path it is given.
     */
    public static function make(string $filename, callable $write): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'cartera-');

        if ($path === false) {
            throw new \RuntimeException('The server could not create a temporary file for the export.');
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
