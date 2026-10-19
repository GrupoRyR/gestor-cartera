<?php

namespace App\Modules\Ingesta\Application;

/**
 * How the error keys of BatchValidator read on screen: "rows.3.saldo"
 * becomes «Fila 3, columna saldo». Rows keep their 0-based number, as in
 * the messages themselves.
 */
final class ErrorKeys
{
    /** Batch-level keys, in the order BatchValidator checks them. */
    private const array BATCH_KEYS = ['batch_id', 'cutoff_date', 'body', 'columns', 'rows'];

    /**
     * The errors in the order BatchValidator reported them: batch-level keys
     * first, then each row by number with its columns in schema order.
     * Stored errors cannot keep that order themselves: MySQL's JSON type
     * sorts object keys by length.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $columns  The dataset's column names, in schema order.
     * @return array<string, list<string>>
     */
    public static function sort(array $errors, array $columns): array
    {
        uksort($errors, fn (string $a, string $b): int => self::rank($a, $columns) <=> self::rank($b, $columns));

        return $errors;
    }

    /**
     * @param  list<string>  $columns
     * @return array{int, int, int, string}
     */
    private static function rank(string $key, array $columns): array
    {
        if (preg_match('/^rows\.(\d+)(?:\.(.+))?$/', $key, $parts) === 1) {
            $column = $parts[2] ?? null;
            $position = $column === null ? -1 : array_search($column, $columns, true);

            // Columns the schema does not know go last, by name.
            return [2, (int) $parts[1], $position === false ? PHP_INT_MAX : $position, (string) $column];
        }

        $position = array_search($key, self::BATCH_KEYS, true);

        return $position === false ? [1, 0, 0, $key] : [0, $position, 0, ''];
    }

    public static function describe(string $key): string
    {
        if (preg_match('/^rows\.(\d+)(?:\.(.+))?$/', $key, $parts) === 1) {
            return isset($parts[2])
                ? __('ingesta::historial.campos.fila_columna', ['fila' => $parts[1], 'columna' => $parts[2]])
                : __('ingesta::historial.campos.fila', ['fila' => $parts[1]]);
        }

        $translation = "ingesta::historial.campos.{$key}";

        return in_array($key, ['batch_id', 'cutoff_date', 'rows', 'body'], true) ? __($translation) : $key;
    }
}
