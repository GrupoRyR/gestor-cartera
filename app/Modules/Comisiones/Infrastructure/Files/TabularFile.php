<?php

namespace App\Modules\Comisiones\Infrastructure\Files;

use Generator;
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
        $rows = [];

        try {
            foreach (self::csvRows($path) as $cells) {
                $rows[] = $cells;

                if (count($rows) > self::MAX_ROWS) {
                    break;
                }
            }
        } catch (UnreadableFile $e) {
            throw new UnreadableFile(__('comisiones::presupuesto.archivo.ilegible'), previous: $e);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new UnreadableFile(__('comisiones::presupuesto.archivo.demasiadas_filas'));
        }

        return $rows;
    }

    /**
     * Every row of a .csv, streamed and with no row cap: the cells as UTF-8
     * strings, an empty row as []. The delimiter and the encoding are
     * detected as for the budget; a BOM is skipped.
     *
     * @return Generator<int, list<string|null>>
     *
     * @throws UnreadableFile When the file cannot be opened; the message is
     *                        not for the user.
     */
    public static function csvRows(string $path): Generator
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new UnreadableFile('The CSV file cannot be opened.');
        }

        try {
            $start = fread($stream, 3) === "\xEF\xBB\xBF" ? 3 : 0;
            fseek($stream, $start);
            $utf8 = self::isUtf8($stream);
            fseek($stream, $start);
            $delimiter = self::delimiter(self::sample($stream));
            fseek($stream, $start);

            // Both encodings keep delimiters and quotes in ASCII, so the raw
            // bytes split the same and each cell is converted afterwards.
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                yield $cells === [null] ? [] : array_map(
                    fn (?string $cell): ?string => $utf8 || $cell === null ? $cell : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252'),
                    $cells,
                );
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Whether the rest of the stream is UTF-8. A file that is not is read as
     * Windows-1252, the encoding Excel uses for CSV in Spanish. Checked line
     * by line: a line break never falls inside a UTF-8 character.
     *
     * @param  resource  $stream
     */
    private static function isUtf8($stream): bool
    {
        while (($line = fgets($stream)) !== false) {
            if (! mb_check_encoding($line, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first non-empty lines of the stream.
     *
     * @param  resource  $stream
     * @return list<string>
     */
    private static function sample($stream): array
    {
        $lines = [];

        while (count($lines) < self::SAMPLE_LINES && ($line = fgets($stream)) !== false) {
            foreach (preg_split('/\r\n|\r|\n/', $line) ?: [] as $piece) {
                if (trim($piece) !== '') {
                    $lines[] = $piece;
                }
            }
        }

        return array_slice($lines, 0, self::SAMPLE_LINES);
    }

    /**
     * The delimiter that splits the most lines into the same number of
     * cells. Counting characters would fail: Colombian amounts hold dots and
     * plain decimals hold commas.
     *
     * @param  list<string>  $lines
     */
    private static function delimiter(array $lines): string
    {
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
