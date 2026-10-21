<?php

namespace App\Modules\Comisiones\Infrastructure\Files;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Opens an uploaded template (the monthly budget, §7.3) as raw rows: the
 * first sheet of an .xlsx, or a .csv whose delimiter (";" or ",") and
 * encoding are detected, since Excel in Spanish saves CSV with ";" and in
 * Windows-1252.
 *
 * Cells come back as the file holds them: strings, and for .xlsx also
 * numbers and dates. Empty rows are kept, so the row number of an error is
 * the one the user sees in Excel.
 */
final class TabularFile
{
    public const array EXTENSIONS = ['csv', 'xlsx'];

    /** Far more rows than seller codes; a bigger file is not a budget. */
    public const int MAX_ROWS = 5_000;

    private const array DELIMITERS = [';', ',', "\t"];

    /** Lines looked at to pick the delimiter. */
    private const int SAMPLE_LINES = 25;

    /**
     * @param  string  $extension  Without dot, as the user named the file.
     * @return list<list<mixed>>
     *
     * @throws UnreadableFile
     */
    public static function rows(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'xlsx' => self::spreadsheet($path),
            'csv' => self::csv($path),
            default => throw new UnreadableFile(__('comisiones::presupuesto.archivo.formato', ['extension' => strtolower($extension)])),
        };
    }

    /**
     * @return list<list<mixed>>
     */
    private static function spreadsheet(string $path): array
    {
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values($row->toArray());

                    if (count($rows) > self::MAX_ROWS) {
                        throw new UnreadableFile(__('comisiones::presupuesto.archivo.demasiadas_filas'));
                    }
                }

                break;
            }
        } catch (UnreadableFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnreadableFile(__('comisiones::presupuesto.archivo.excel_danado'), previous: $e);
        } finally {
            $reader->close();
            unset($reader);
            // The row iterators keep the XML stream in reference cycles;
            // collecting them frees the file, which Windows needs to delete it.
            gc_collect_cycles();
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private static function csv(string $path): array
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new UnreadableFile(__('comisiones::presupuesto.archivo.ilegible'));
        }

        $content = self::utf8($content);
        $delimiter = self::delimiter($content);
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new UnreadableFile(__('comisiones::presupuesto.archivo.ilegible'));
        }

        fwrite($stream, $content);
        rewind($stream);
        $rows = [];

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = $cells === [null] ? [] : $cells;

            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);

                throw new UnreadableFile(__('comisiones::presupuesto.archivo.demasiadas_filas'));
            }
        }

        fclose($stream);

        return $rows;
    }

    /**
     * UTF-8 without BOM. A file that is not valid UTF-8 is read as
     * Windows-1252, the encoding Excel uses for CSV in Spanish.
     */
    private static function utf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * The delimiter that splits the most lines into the same number of
     * cells. Counting characters would fail: Colombian amounts hold dots and
     * plain decimals hold commas.
     */
    private static function delimiter(string $content): string
    {
        $lines = array_slice(array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $content) ?: [],
            fn (string $line): bool => trim($line) !== '',
        )), 0, self::SAMPLE_LINES);

        $best = ';';
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $widths = [];

            foreach ($lines as $line) {
                $width = count(str_getcsv($line, $delimiter, '"', ''));

                if ($width > 1) {
                    $widths[$width] = ($widths[$width] ?? 0) + 1;
                }
            }

            if ($widths === []) {
                continue;
            }

            // The most common width wins; wider breaks ties.
            $score = max($widths) * 1000 + (int) array_search(max($widths), $widths, true);

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
