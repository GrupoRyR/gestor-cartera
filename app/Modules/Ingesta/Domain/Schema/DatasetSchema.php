<?php

namespace App\Modules\Ingesta\Domain\Schema;

use App\Modules\Core\Contracts\Enums\Dataset;
use InvalidArgumentException;

/**
 * The columns of a dataset (a "Lote" of etl/esquema.py): their order, types
 * and required flags, the natural key and whether a load may be empty.
 */
final readonly class DatasetSchema
{
    /**
     * @param  list<Column>  $columns  In the canonical order rows are stored in.
     * @param  list<string>  $key  Column names of the natural key; empty when the dataset has none.
     * @param  bool  $allowEmpty  Whether a batch without rows is valid.
     *
     * @throws InvalidArgumentException When a column repeats or a key column does not exist.
     */
    public function __construct(
        public Dataset $dataset,
        public array $columns,
        public array $key = [],
        public bool $allowEmpty = false,
    ) {
        $names = array_map(fn (Column $column): string => $column->name, $columns);

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException("The {$dataset->value} schema repeats a column.");
        }

        foreach ($key as $name) {
            if (! in_array($name, $names, true)) {
                throw new InvalidArgumentException("The {$dataset->value} key column [{$name}] is not in the schema.");
            }
        }
    }

    /**
     * Column names in the canonical order.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(fn (Column $column): string => $column->name, $this->columns);
    }

    /**
     * The column with that name; null when the schema has none.
     */
    public function column(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }
}
