<?php

namespace App\Modules\Bancos\Infrastructure\Files;

use DOMDocument;
use DOMElement;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Opens a bank statement as raw rows, whatever its container: .xlsx (first
 * sheet), .csv/.txt, or an .xls that is really an HTML table (what some bank
 * portals, Davivienda's among them, export under that extension). The
 * delimiter and the encoding of a CSV are detected, because each bank (and
 * each export option) picks its own. A real binary .xls (Excel 97) is refused
 * with a message that says how to save it.
 *
 * Cells come back raw, as the file holds them (strings, and for .xlsx also
 * numbers and dates); the bank reader gives them meaning. Empty rows are kept
 * so a row number in an error is the one the user sees in Excel.
 */
final class TabularFile
{
    public const array EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /** More rows than a year of one account can have; a bigger file is not a statement. */
    public const int MAX_ROWS = 50_000;

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
            'xls' => self::htmlTable($path),
            default => throw new UnreadableFile('El formato «.'.$extension.'» no es válido. Sube un archivo .xlsx, .xls, .csv o .txt.'),
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
        $content = self::utf8(self::contents($path));
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
     * Every <tr> of the document, in order, as the texts of its cells. Rows
     * outside the table of movements (a title, the totals at the foot) come
     * back too: the bank reader ignores what is not a movement.
     *
     * @return list<list<string>>
     */
    private static function htmlTable(string $path): array
    {
        $content = self::contents($path);
        $start = ltrim(str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content);

        if (! str_starts_with($start, '<')) {
            throw new UnreadableFile('Los archivos .xls antiguos no se pueden leer. Guárdalo como .xlsx o .csv desde Excel y vuélvelo a subir.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // The XML declaration tells the HTML parser the text is UTF-8, so accents survive.
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.self::utf8($content), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new UnreadableFile('No pude leer el archivo .xls.');
        }

        $rows = [];

        foreach ($document->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $node) {
                if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), ['td', 'th'], true)) {
                    $cells[] = self::htmlCell($node->textContent);
                }
            }

            $rows[] = $cells;

            if (count($rows) > self::MAX_ROWS) {
                throw new UnreadableFile('El archivo tiene demasiadas filas para ser un extracto.');
            }
        }

        return $rows;
    }

    /**
     * The text of an HTML cell with its whitespace collapsed, without the
     * leading apostrophe Excel uses to mark a number as text ('0000123).
     */
    private static function htmlCell(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));

        return str_starts_with($text, "'") ? substr($text, 1) : $text;
    }

    /**
     * @throws UnreadableFile
     */
    private static function contents(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new UnreadableFile('No pude leer el archivo.');
        }

        return $content;
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
