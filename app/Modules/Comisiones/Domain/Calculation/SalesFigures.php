<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * The sales part of a code settlement (§5.2), as SalesCommission computes it.
 */
final readonly class SalesFigures
{
    /**
     * @param  list<SaleDetail>  $details  Sorted by document date, then key.
     */
    public function __construct(
        public Money $sales,
        public ?Money $budget,
        public Percentage $compliance,
        public bool $meetsMinimum,
        public Money $commission,
        public array $details,
    ) {}
}
