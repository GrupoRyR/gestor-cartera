<?php

namespace App\Modules\Ingesta\Domain\Schema;

/**
 * One column of a dataset, as etl/esquema.py declares it with Columna().
 */
final readonly class Column
{
    /**
     * @param  string  $name  The snake_case row key ("pg" name in esquema.py).
     * @param  bool  $required  A required column rejects the batch when its value is null.
     * @param  string  $sourceHeader  The HANA alias ("hana" in esquema.py), also the header of the
     *                                manual Excel upload.
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $required,
        public string $sourceHeader,
    ) {}
}
