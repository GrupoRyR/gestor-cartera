<?php

namespace App\Modules\Ingesta\Application\Manual;

/**
 * The data rows of a cartera workbook, as read: raw cell values by cartera
 * column name, with the Excel row number of each.
 */
final readonly class CarteraSheet
{
    /**
     * @param  list<array{line: int, values: array<string, mixed>}>  $rows  Only the mapped columns.
     * @param  list<string>  $ignoredHeaders  Headers no cartera column takes, as written.
     */
    public function __construct(
        public array $rows,
        public array $ignoredHeaders,
    ) {}
}
