<?php

namespace App\Modules\Core\Console\Migracion;

use Generator;

/**
 * A CSV export of a legacy system, as phpMyAdmin and Supabase write them: a
 * header row, UTF-8 with or without BOM, comma or semicolon delimiter
 * (taken from the header line) and double quotes around values that need
 * them. Backslashes are plain characters.
 *
 * Header names are trimmed and lowercased. Only the columns asked for in
 * open() are kept, so other columns (a password hash, say) are never read.
 * Problems with the whole file throw InvalidCsvFile; problems with one row
 * travel with that row (CsvRow::assertReadable()).
 */
final class CsvFile
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param  int  $width  The number of columns in the header.
     * @param  array<string, int>  $positions  The index of each kept column.
     */
    private function __construct(
        public readonly string $name,
        private readonly string $path,
        private readonly string $delimiter,
        private readonly int $width,
        private readonly array $positions,
    ) {}

    /**
     * Reads the header of the file at $path and checks it has $columns
     * (lowercase names); extra columns are ignored.
     *
     * @param  list<string>  $columns
     *
     * @throws InvalidCsvFile When the file cannot be read, is empty, is not UTF-8 or lacks a column.
     */
    public static function open(string $path, array $columns): self
    {
        $name = basename($path);
        $handle = self::handle($path);
        $first = fgets($handle);
        fclose($handle);

        $first = $first === false ? '' : self::withoutBom($first);

        if (trim($first) === '') {
            throw new InvalidCsvFile("El archivo {$name} está vacío: le falta la fila con los nombres de las columnas.");
        }

        if (! mb_check_encoding($first, 'UTF-8')) {
            throw new InvalidCsvFile("El archivo {$name} no está en UTF-8: expórtalo de nuevo con el conjunto de caracteres utf-8.");
        }

        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(
            fn (?string $column): string => mb_strtolower(trim((string) $column)),
            str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', ''),
        );

        $missing = array_values(array_diff($columns, $header));

        if ($missing !== []) {
            throw new InvalidCsvFile("Al archivo {$name} le faltan columnas: ".implode(', ', $missing).'.');
        }

        $positions = array_intersect_key(array_flip($header), array_flip($columns));

        return new self($name, $path, $delimiter, count($header), $positions);
    }

    /**
     * The data rows in file order, each with the line it starts on. Blank
     * lines (and lines with only delimiters) are skipped.
     *
     * @return Generator<int, CsvRow>
     */
    public function rows(): Generator
    {
        $handle = self::handle($this->path);

        try {
            $header = fgetcsv($handle, null, $this->delimiter, '"', '');
            $line = 1 + ($header === false ? 0 : self::lineCount($header));

            while (($fields = fgetcsv($handle, null, $this->delimiter, '"', '')) !== false) {
                $start = $line;
                $line += self::lineCount($fields);

                if (implode('', array_map(fn (?string $field): string => trim((string) $field), $fields)) !== '') {
                    yield $this->row($start, $fields);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $fields
     */
    private function row(int $line, array $fields): CsvRow
    {
        $legacyId = isset($this->positions['id']) ? mb_scrub(trim((string) ($fields[$this->positions['id']] ?? '')), 'UTF-8') : '';

        foreach ($fields as $field) {
            if (! mb_check_encoding((string) $field, 'UTF-8')) {
                return CsvRow::unreadable($this->name, $line, 'la fila no está en UTF-8: revisa que el archivo se exportó en utf-8', $legacyId);
            }
        }

        if (count($fields) !== $this->width) {
            return CsvRow::unreadable($this->name, $line, sprintf('la fila tiene %d columnas y el encabezado %d', count($fields), $this->width), $legacyId);
        }

        $values = [];

        foreach ($this->positions as $column => $index) {
            $values[$column] = (string) $fields[$index];
        }

        return new CsvRow($this->name, $line, $values);
    }

    /**
     * How many lines of the file a record takes: one, plus the line breaks
     * inside its quoted values.
     *
     * @param  array<int, string|null>  $fields
     */
    private static function lineCount(array $fields): int
    {
        return 1 + substr_count(implode('', array_map(fn (?string $field): string => (string) $field, $fields)), "\n");
    }

    private static function withoutBom(string $line): string
    {
        return str_starts_with($line, self::BOM) ? substr($line, strlen(self::BOM)) : $line;
    }

    /**
     * @return resource
     *
     * @throws InvalidCsvFile
     */
    private static function handle(string $path)
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new InvalidCsvFile('No pude abrir el archivo '.basename($path).'.');
        }

        return $handle;
    }
}
