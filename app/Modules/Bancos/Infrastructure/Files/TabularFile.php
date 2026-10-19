<?php

namespace App\Modules\Bancos\Infrastructure\Files;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Opens a bank statement as raw rows, whatever its container: .xlsx (first
 * sheet) or .csv/.txt. The delimiter and the encoding of a CSV are detected,
 * because each bank (and each export option) picks its own.
 *
 * Cells come back raw, as the file holds them (strings, and for .xlsx also
 * numbers and dates); the bank reader gives them meaning. Empty rows are kept
 * so a row number in an error is the one the user sees in Excel.
 */
final class TabularFile
{
    public const array EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /** More rows than a year of one account can have; a bigger file is not a statement. */
    public const int MAX_ROWS = 100_000;

    private const array DELIMITERS = [';', ',', "\t", '|'];

    /** Lines looked at to pick the delimiter. */
    private const int SAMPLE_LINES = 25;

    /**
     * @param  string|null  $extension  Without dot; taken from the path when null.
     * @return list<list<mixed>>
     *
     * @throws UnreadableFile
     */
    public static function rows(string $path, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => self::spreadsheet($path),
            'csv', 'txt' => self::csv($path),
            'xls' => throw new UnreadableFile('Los archivos .xls antiguos no se pueden leer. Guárdalo como .xlsx o .csv desde Excel y vuélvelo a subir.'),
            default => throw new UnreadableFile('El formato «.'.$extension.'» no es válido. Sube un archivo .xlsx, .csv o .txt.'),
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
                        throw new UnreadableFile('El archivo tiene demasiadas filas para ser un extracto.');
                    }
                }

                break;
            }
        } catch (UnreadableFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnreadableFile('No pude abrir el archivo de Excel. Revisa que no esté dañado ni protegido con contraseña.', previous: $e);
        } finally {
            $reader->close();
            unset($reader);
            // The row iterators keep the XML stream in reference cycles; collecting
            // them frees the file, which Windows needs before it can be moved.
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
            throw new UnreadableFile('No pude leer el archivo.');
        }

        $content = self::utf8($content);
        $delimiter = self::delimiter($content);

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new UnreadableFile('No pude leer el archivo.');
        }

        fwrite($stream, $content);
        rewind($stream);

        $rows = [];

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = $cells === [null] ? [] : $cells;

            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);

                throw new UnreadableFile('El archivo tiene demasiadas filas para ser un extracto.');
            }
        }

        fclose($stream);

        return $rows;
    }

    /**
     * UTF-8 without BOM. A file that is not valid UTF-8 is read as Windows-1252,
     * the encoding Excel and the Colombian bank portals export.
     */
    private static function utf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * The delimiter that splits the most lines into the same number of cells.
     * Counting characters would fail: Colombian amounts hold commas.
     */
    private static function delimiter(string $content): string
    {
        $lines = array_slice(array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $content) ?: [],
            fn (string $line): bool => trim($line) !== '',
        )), 0, self::SAMPLE_LINES);

        $best = ',';
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
            $score = max($widths) * 1000 + array_search(max($widths), $widths, true);

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
