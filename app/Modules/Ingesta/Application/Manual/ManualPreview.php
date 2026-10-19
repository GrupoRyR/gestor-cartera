<?php

namespace App\Modules\Ingesta\Application\Manual;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Application\ErrorKeys;
use App\Modules\Ingesta\Domain\Cents;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use InvalidArgumentException;
use OverflowException;

/**
 * A manual cartera upload ready to confirm: the batch it would send to
 * IngestaSink, the Excel row of each batch row, the ignored headers and
 * the BatchValidator errors, with the figures the preview page shows.
 */
final readonly class ManualPreview
{
    /**
     * @param  list<int>  $lines  Excel row number of each batch row.
     * @param  list<string>  $ignoredHeaders
     * @param  array<string, list<string>>  $errors  Of BatchValidator; empty when the batch is valid.
     */
    public function __construct(
        public IncomingBatch $batch,
        public array $lines,
        public array $ignoredHeaders,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function rowCount(): int
    {
        return count($this->batch->rows);
    }

    public function clientCount(): int
    {
        return count(array_unique(array_filter(array_column($this->batch->rows, 'codigo_sn'), fn (mixed $code): bool => $code !== null)));
    }

    public function withoutAdvisorCount(): int
    {
        return count(array_filter($this->batch->rows, fn (array $row): bool => $row['slp_code'] === null));
    }

    /**
     * Sum of "saldo" and of the overdue "saldo" ("dias" > 0), in pesos;
     * nulls when a saldo cannot be read (the errors say which).
     *
     * @return array{total: ?float, overdue: ?float}
     */
    public function totals(): array
    {
        $total = 0;
        $overdue = 0;

        try {
            foreach ($this->batch->rows as $row) {
                $balance = Cents::fromDecimal((string) $row['saldo']);
                $total = Cents::add($total, $balance);
                $overdue = is_int($row['dias']) && $row['dias'] > 0 ? Cents::add($overdue, $balance) : $overdue;
            }
        } catch (InvalidArgumentException|OverflowException) {
            return ['total' => null, 'overdue' => null];
        }

        return ['total' => Cents::toFloat($total), 'overdue' => Cents::toFloat($overdue)];
    }

    /**
     * The first rows, for the preview table.
     *
     * @return list<array<string, scalar|null>>
     */
    public function sample(int $count = 20): array
    {
        return array_slice($this->batch->rows, 0, $count);
    }

    /**
     * The errors with their place in the Excel: «Fila 15 de Excel, columna
     * «Saldo»» instead of "rows.13.saldo".
     *
     * @return list<array{field: string, messages: list<string>}>
     */
    public function errorList(): array
    {
        $schema = DatasetSchemas::for(Dataset::Receivables);
        $list = [];

        foreach ($this->errors as $key => $messages) {
            $field = ErrorKeys::describe($key);

            if (preg_match('/^rows\.(\d+)(?:\.(.+))?$/', $key, $parts) === 1 && isset($this->lines[(int) $parts[1]])) {
                $line = $this->lines[(int) $parts[1]];
                $field = isset($parts[2])
                    ? __('ingesta::manual.vista_previa.fila_columna', ['fila' => $line, 'columna' => $schema->column($parts[2])->sourceHeader ?? $parts[2]])
                    : __('ingesta::manual.vista_previa.fila', ['fila' => $line]);
            }

            $list[] = ['field' => $field, 'messages' => $messages];
        }

        return $list;
    }
}
