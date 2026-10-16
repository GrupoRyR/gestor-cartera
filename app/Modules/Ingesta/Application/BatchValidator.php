<?php

namespace App\Modules\Ingesta\Application;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Ingesta\Domain\Schema\Column;
use App\Modules\Ingesta\Domain\Schema\ColumnType;
use App\Modules\Ingesta\Domain\Schema\DatasetSchema;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use Carbon\CarbonImmutable;

/**
 * Checks a batch against its dataset schema (etl/esquema.py) before
 * IngestaSink stores it: the batch id, the cutoff date, the row count, the
 * columns of every row, the value of every cell and the natural key.
 *
 * Errors are keyed like Laravel validation errors ("batch_id",
 * "cutoff_date", "rows", "rows.3", "rows.3.saldo"), with Spanish messages
 * for the robot and the load history, and stop at
 * ValidationErrors::MAX_KEYS keys.
 */
final class BatchValidator
{
    public const int MAX_BATCH_ID_LENGTH = 120;

    public const int MAX_TEXT_LENGTH = 4000;

    private const string BATCH_ID = '/^[A-Za-z0-9._:-]+$/';

    private const string DECIMAL2 = '/^-?\d{1,17}(\.\d{1,2})?$/';

    private const string DECIMAL6 = '/^-?\d{1,13}(\.\d{1,6})?$/';

    private const string DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * @return array<string, list<string>> Empty when the batch is valid.
     */
    public function errors(IncomingBatch $batch): array
    {
        $errors = new ValidationErrors;
        $schema = DatasetSchemas::for($batch->dataset);

        $this->checkBatchId($batch->batchId, $errors);
        $this->checkCutoffDate($batch->cutoffDate, $errors);

        if ($this->checkRowCount($batch, $schema, $errors)) {
            $this->checkRows($batch->rows, $schema, $errors);
        }

        return $errors->all();
    }

    /**
     * The text stored as LoadRecord::$error: the only message, or how many
     * there are.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function summarize(array $errors): string
    {
        $messages = array_merge(...array_values($errors));

        if (count($messages) === 1) {
            return $messages[0];
        }

        return count($errors) >= ValidationErrors::MAX_KEYS
            ? __('ingesta::validacion.resumen_tope', ['count' => count($messages)])
            : __('ingesta::validacion.resumen', ['count' => count($messages)]);
    }

    private function checkBatchId(string $batchId, ValidationErrors $errors): void
    {
        if (trim($batchId) === '') {
            $errors->add('batch_id', __('ingesta::validacion.batch_id.requerido'));
        } elseif (strlen($batchId) > self::MAX_BATCH_ID_LENGTH) {
            $errors->add('batch_id', __('ingesta::validacion.batch_id.largo', ['max' => self::MAX_BATCH_ID_LENGTH]));
        } elseif (preg_match(self::BATCH_ID, $batchId) !== 1) {
            $errors->add('batch_id', __('ingesta::validacion.batch_id.formato'));
        }
    }

    /**
     * The cutoff date is a calendar day: compared as "Y-m-d" with today in
     * the application timezone (America/Bogota).
     */
    private function checkCutoffDate(CarbonImmutable $cutoffDate, ValidationErrors $errors): void
    {
        $cutoff = $cutoffDate->format('Y-m-d');
        $today = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d');

        if ($cutoff > $today) {
            $errors->add('cutoff_date', __('ingesta::validacion.corte_futuro', ['corte' => $cutoff, 'hoy' => $today]));
        }
    }

    /**
     * @return bool Whether the rows are worth checking one by one.
     */
    private function checkRowCount(IncomingBatch $batch, DatasetSchema $schema, ValidationErrors $errors): bool
    {
        $count = count($batch->rows);
        $maxRows = (int) config('ingesta.max_rows');

        if ($count === 0 && ! $schema->allowEmpty) {
            $errors->add('rows', __('ingesta::validacion.sin_filas', ['lote' => mb_strtolower($batch->dataset->label())]));
        } elseif ($count > $maxRows) {
            $errors->add('rows', __('ingesta::validacion.demasiadas_filas', ['filas' => $count, 'max' => $maxRows]));

            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function checkRows(array $rows, DatasetSchema $schema, ValidationErrors $errors): void
    {
        $names = $schema->columnNames();
        $lote = mb_strtolower($schema->dataset->label());

        /** @var array<string, int> $keys Row index of every natural key seen, by serialized key. */
        $keys = [];

        foreach ($rows as $index => $row) {
            if ($errors->full()) {
                return;
            }

            if (! $this->hasColumns($row, $names, "rows.{$index}", $lote, $errors)) {
                continue;
            }

            foreach ($schema->columns as $column) {
                $this->checkValue($row[$column->name], $column, "rows.{$index}.{$column->name}", $errors);
            }

            if ($schema->key !== []) {
                $this->checkKey($row, $index, $schema->key, $keys, $errors);
            }
        }
    }

    /**
     * Whether the row has exactly the schema's columns; reports the missing
     * and the unknown ones otherwise.
     *
     * @param  array<string, scalar|null>  $row
     * @param  list<string>  $names
     */
    private function hasColumns(array $row, array $names, string $key, string $lote, ValidationErrors $errors): bool
    {
        $present = array_map(strval(...), array_keys($row));
        $missing = array_diff($names, $present);
        $unknown = array_diff($present, $names);

        if ($missing !== []) {
            $errors->add($key, __('ingesta::validacion.columnas_faltantes', ['columnas' => implode(', ', $missing)]));
        }

        if ($unknown !== []) {
            $errors->add($key, __('ingesta::validacion.columnas_desconocidas', ['lote' => $lote, 'columnas' => implode(', ', $unknown)]));
        }

        return $missing === [] && $unknown === [];
    }

    private function checkValue(mixed $value, Column $column, string $key, ValidationErrors $errors): void
    {
        $replace = ['columna' => $column->name];

        // A required value is missing when null or, as esquema.py says, blank.
        if ($value === null || ($column->required && is_string($value) && trim($value) === '')) {
            if ($column->required) {
                $errors->add($key, __('ingesta::validacion.requerida', $replace));
            }

            return;
        }

        $message = match ($column->type) {
            ColumnType::Text => match (true) {
                ! is_string($value) => __('ingesta::validacion.texto', $replace),
                mb_strlen($value) > self::MAX_TEXT_LENGTH => __('ingesta::validacion.texto_largo', $replace + ['max' => self::MAX_TEXT_LENGTH]),
                default => null,
            },
            ColumnType::Integer => is_int($value) ? null : __('ingesta::validacion.entero', $replace),
            ColumnType::Decimal2 => $this->matches(self::DECIMAL2, $value) ? null : __('ingesta::validacion.decimal2', $replace),
            ColumnType::Decimal6 => $this->matches(self::DECIMAL6, $value) ? null : __('ingesta::validacion.decimal6', $replace),
            ColumnType::Date => $this->isDate($value) ? null : __('ingesta::validacion.fecha', $replace),
        };

        if ($message !== null) {
            $errors->add($key, $message);
        }
    }

    /**
     * Reports a row whose natural key an earlier row already has. Null is
     * a key value like any other (a client's general contact row has no
     * contacto_id).
     *
     * @param  array<string, scalar|null>  $row
     * @param  list<string>  $key
     * @param  array<string, int>  $keys
     */
    private function checkKey(array $row, int $index, array $key, array &$keys, ValidationErrors $errors): void
    {
        $values = array_map(fn (string $name): mixed => $row[$name], $key);
        $serialized = serialize($values);

        if (! isset($keys[$serialized])) {
            $keys[$serialized] = $index;

            return;
        }

        $described = array_map(
            fn (string $name, mixed $value): string => $name.' = '.($value === null ? __('ingesta::validacion.vacio') : (string) $value),
            $key,
            $values,
        );

        $errors->add("rows.{$index}", __('ingesta::validacion.llave_repetida', [
            'llave' => implode(', ', $described),
            'fila' => $keys[$serialized],
        ]));
    }

    private function matches(string $pattern, mixed $value): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::DATE, $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
