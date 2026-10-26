<?php

namespace App\Modules\Ingesta\Infrastructure\Manual;

use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Application\Manual\CarteraSheet;
use App\Modules\Ingesta\Application\Manual\UnreadableWorkbook;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Throwable;

/**
 * Reads the SAP "Cartera del día" Excel export: the sheet named "Datos" if
 * there is one, else the first; the header row is the first of the first
 * 20 rows that has both «Código SN» and «Nit». Headers map to the cartera
 * columns by their HANA alias (Column::sourceHeader), compared trimmed,
 * case-insensitive and with spaces collapsed; accents count.
 *
 * Cell values come back raw (int, float, string, DateTimeInterface or
 * null): CellConverter turns them into the contract formats.
 *
 * A sheet may hold at most config('ingesta.max_rows') data rows, and no
 * more than that plus HEADER_SEARCH_ROWS lines are read, empty ones
 * included, so a sheet of millions of empty rows also stops early.
 */
final class CarteraWorkbookReader
{
    public const string PREFERRED_SHEET = 'Datos';

    /** Rows searched for the header row. */
    public const int HEADER_SEARCH_ROWS = 20;

    /** Headers the header row is recognized by. */
    public const array HEADER_MARKERS = ['Código SN', 'Nit'];

    /** Headers a manual cartera cannot do without. */
    public const array REQUIRED_HEADERS = ['Código SN', 'Nit', 'Documento', 'Fecha vencimiento', 'Saldo'];

    /**
     * @throws UnreadableWorkbook With a Spanish message for the user.
     */
    public function read(string $path): CarteraSheet
    {
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        $message = '';

        try {
            $reader->open($path);

            return $this->readSheet($this->sheet($reader));
        } catch (UnreadableWorkbook $e) {
            // Thrown again below, without this trace: its arguments hold the
            // sheet, whose half-read rows would keep the file open.
            $message = $e->getMessage();
            unset($e);
        } catch (Throwable $e) {
            throw new UnreadableWorkbook(__('ingesta::manual.errores.ilegible'), previous: $e);
        } finally {
            $reader->close();
            unset($reader);
            // The sheet and row iterators keep their XML streams of the file
            // in reference cycles; collecting them releases the handle, which
            // Windows needs before the upload can be deleted.
            gc_collect_cycles();
        }

        throw new UnreadableWorkbook($message);
    }

    /**
     * The "Datos" sheet, else the first one.
     */
    private function sheet(Reader $reader): Sheet
    {
        $first = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $first ??= $sheet;

            if (self::normalize($sheet->getName()) === self::normalize(self::PREFERRED_SHEET)) {
                return $sheet;
            }
        }

        return $first ?? throw new UnreadableWorkbook(__('ingesta::manual.errores.sin_hojas'));
    }

    /**
     * @throws UnreadableWorkbook When the header row or a required column is missing, or past ingesta.max_rows.
     */
    private function readSheet(Sheet $sheet): CarteraSheet
    {
        $maxRows = (int) config('ingesta.max_rows');
        $columns = null;
        $ignored = [];
        $rows = [];
        $line = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $line++;

            if ($line > $maxRows + self::HEADER_SEARCH_ROWS) {
                throw $this->tooManyRows($maxRows);
            }

            if ($columns === null) {
                if ($line > self::HEADER_SEARCH_ROWS) {
                    break;
                }

                if ($this->isHeaderRow($row)) {
                    [$columns, $ignored] = $this->mapHeaders($row);
                }

                continue;
            }

            $values = $this->values($row, $columns);

            if ($values !== null) {
                $rows[] = ['line' => $line, 'values' => $values];

                if (count($rows) > $maxRows) {
                    throw $this->tooManyRows($maxRows);
                }
            }
        }

        if ($columns === null) {
            throw new UnreadableWorkbook(__('ingesta::manual.errores.sin_encabezados', [
                'filas' => self::HEADER_SEARCH_ROWS,
                'columnas' => '«'.implode('» y «', self::HEADER_MARKERS).'»',
            ]));
        }

        $headers = array_map(fn (string $name): string => DatasetSchemas::for(Dataset::Receivables)->column($name)->sourceHeader ?? $name, $columns);
        $missing = array_values(array_filter(self::REQUIRED_HEADERS, fn (string $header): bool => ! in_array($header, $headers, true)));

        if ($missing !== []) {
            throw new UnreadableWorkbook(__('ingesta::manual.errores.columnas_faltantes', ['columnas' => '«'.implode('», «', $missing).'»']));
        }

        return new CarteraSheet($rows, $ignored);
    }

    private function tooManyRows(int $maxRows): UnreadableWorkbook
    {
        return new UnreadableWorkbook(__('ingesta::manual.errores.demasiadas_filas', ['max' => number_format($maxRows, 0, ',', '.')]));
    }

    private function isHeaderRow(Row $row): bool
    {
        $cells = array_map(fn (mixed $value): string => is_string($value) ? self::normalize($value) : '', $row->toArray());

        foreach (self::HEADER_MARKERS as $marker) {
            if (! in_array(self::normalize($marker), $cells, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Column name by cell position, and the headers no column takes.
     *
     * @return array{array<int, string>, list<string>}
     */
    private function mapHeaders(Row $row): array
    {
        $byHeader = [];

        foreach (DatasetSchemas::for(Dataset::Receivables)->columns as $column) {
            $byHeader[self::normalize($column->sourceHeader)] = $column->name;
        }

        $columns = [];
        $ignored = [];

        foreach ($row->toArray() as $position => $value) {
            $header = is_scalar($value) ? trim((string) $value) : '';

            if ($header === '') {
                continue;
            }

            $name = $byHeader[self::normalize($header)] ?? null;

            // A repeated header keeps its first column.
            if ($name === null || in_array($name, $columns, true)) {
                $ignored[] = $header;
            } else {
                $columns[(int) $position] = $name;
            }
        }

        return [$columns, $ignored];
    }

    /**
     * The mapped values of a data row; null for a row to skip: fully empty,
     * or without both client code and document (a totals line).
     *
     * @param  array<int, string>  $columns
     * @return array<string, mixed>|null
     */
    private function values(Row $row, array $columns): ?array
    {
        $cells = $row->toArray();
        $values = [];

        foreach ($columns as $position => $name) {
            $value = $cells[$position] ?? null;
            $values[$name] = is_string($value) && trim($value) === '' ? null : $value;
        }

        if (($values['codigo_sn'] ?? null) === null && ($values['documento'] ?? null) === null) {
            return null;
        }

        return $values;
    }

    /**
     * Trimmed, lowercase and with runs of whitespace as one space.
     */
    public static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text)));
    }
}
