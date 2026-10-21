<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The monthly grid of one company, as one sheet of the analyst's workbook.
 */
final readonly class MonthGrid
{
    /**
     * @param  list<MonthWeek>  $weeks  The rows, in date order.
     * @param  list<float>  $columnTotals  Pesos per weekday column, Monday to Friday.
     * @param  float  $total  Pesos of the month.
     * @param  int  $businessDays  Business days of the month.
     */
    public function __construct(
        public CompanyCode $company,
        public array $weeks,
        public array $columnTotals,
        public float $total,
        public int $businessDays,
    ) {}
}
