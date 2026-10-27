<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use App\Modules\Comisiones\Infrastructure\Captures\Supports;
use App\Modules\Comisiones\Infrastructure\Files\TabularFile;
use App\Modules\Comisiones\Infrastructure\Files\UnreadableFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The content of a capture support (S-32): what the file really is, as
 * finfo reads it, must fit its extension, and a .csv cannot hold a cell
 * that Excel would run as a formula when the approver opens it. The
 * "file", "extensions" and "max" rules report the other failures.
 */
final class SupportFile implements ValidationRule
{
    /**
     * The MIME types finfo reports for each extension. A real .xlsx may read
     * as the zip it is; a CSV with ";", a BOM or in Windows-1252 reads as
     * plain text.
     */
    private const array TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
    ];

    /** How a formula starts in Excel, after the leading whitespace. */
    private const array FORMULA_STARTS = ['=', '+', '-', '@'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() > Supports::MAX_KB * 1024) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $types = self::TYPES[$extension] ?? null;

        if ($types === null) {
            return;
        }

        if (! in_array($value->getMimeType(), $types, true)) {
            $fail(__('comisiones::ajustes.validacion.soporte_contenido', ['extension' => strtoupper($extension)]));

            return;
        }

        if ($extension !== 'csv') {
            return;
        }

        try {
            $cell = self::firstFormula($value->getPathname());
        } catch (UnreadableFile) {
            $fail(__('comisiones::ajustes.validacion.soporte_contenido', ['extension' => 'CSV']));

            return;
        }

        if ($cell !== null) {
            $fail(__('comisiones::ajustes.validacion.soporte_formula', ['fila' => $cell[0], 'columna' => $cell[1]]));
        }
    }

    /**
     * The row and column (from 1) of the first formula cell, or null.
     *
     * @return array{int, int}|null
     *
     * @throws UnreadableFile
     */
    private static function firstFormula(string $path): ?array
    {
        $row = 0;

        foreach (TabularFile::csvRows($path) as $cells) {
            $row++;

            foreach ($cells as $index => $cell) {
                if ($cell !== null && self::isFormula($cell)) {
                    return [$row, $index + 1];
                }
            }
        }

        return null;
    }

    /**
     * A cell that starts with = + - or @ once the leading whitespace is
     * trimmed, unless it is a number: plain ("-5000", "+5.5") or Colombian
     * ("-5.000,50", "$ -5.000").
     */
    private static function isFormula(string $cell): bool
    {
        $text = ltrim($cell, " \t\r\n");

        if ($text === '' || ! in_array($text[0], self::FORMULA_STARTS, true)) {
            return false;
        }

        return ! is_numeric($text) && Amount::parse($text) === null;
    }
}
