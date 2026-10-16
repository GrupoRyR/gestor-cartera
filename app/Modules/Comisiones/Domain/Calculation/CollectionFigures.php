<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The collection part of a code settlement (§5.3, §5.4), as
 * CollectionCommission computes it.
 */
final readonly class CollectionFigures
{
    /**
     * @param  Money  $collected  Paid base of the code's payments and crossings.
     * @param  Money  $commission  Sum of the exact line commissions, rounded once to the peso, half up.
     * @param  list<CollectionDetail>  $details  Sorted by payment date, then key (§8.2).
     */
    public function __construct(
        public Money $collected,
        public Money $commission,
        public array $details,
    ) {}
}
