<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * The credits of one bank of one company on one day, as the storage adds them
 * up. Amounts are whole cents, so adding them is exact.
 */
final readonly class DayBankTotal
{
    /**
     * @param  CarbonImmutable  $date  Movement date (date only, America/Bogota).
     * @param  int  $cents  Sum of the credits, in cents of the collection currency.
     * @param  int  $movements  Number of credits summed.
     */
    public function __construct(
        public CarbonImmutable $date,
        public CompanyCode $company,
        public Bank $bank,
        public int $cents,
        public int $movements,
    ) {}
}
