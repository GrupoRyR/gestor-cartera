<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * Collections (recaudo) of one company in one ISO week, in Colombian pesos.
 */
final readonly class WeeklyCollection
{
    /**
     * @param  CarbonImmutable  $weekStart  Monday of the ISO week (date only, America/Bogota).
     * @param  CarbonImmutable  $weekEnd  Sunday of the same week.
     * @param  float  $total  Sum of the daily totals of the week inside the requested range.
     * @param  array<string, float>  $byBank  Amount per bank, keyed by Bank value; a missing
     *                                        bank had no credits that week.
     */
    public function __construct(
        public CarbonImmutable $weekStart,
        public CarbonImmutable $weekEnd,
        public CompanyCode $company,
        public float $total,
        public array $byBank,
    ) {}
}
