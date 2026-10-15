<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * Collections (recaudo) of one company on one day: the sum of bank credits
 * by movement date, in Colombian pesos.
 */
final readonly class DailyCollection
{
    /**
     * @param  CarbonImmutable  $date  Movement date (date only, America/Bogota).
     * @param  float  $total  Sum of every bank credit of the day.
     * @param  array<string, float>  $byBank  Amount per bank, keyed by Bank value; a missing
     *                                        bank had no credits that day.
     * @param  int  $movementCount  Number of credit movements summed.
     */
    public function __construct(
        public CarbonImmutable $date,
        public CompanyCode $company,
        public float $total,
        public array $byBank,
        public int $movementCount,
    ) {}
}
