<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\DerivedCommissionRule;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * A commission derived from another code's sales or collections (81.1, 85.1; §5.5).
 */
final readonly class DerivedSettlement
{
    /**
     * @param  Money  $base  The source code's sales, or its collected base.
     * @param  Money  $commission  base × rate, rounded to the peso.
     * @param  list<CollectionDetail>  $details  For a collections base: the source lines re-rated
     *                                           at the derived rate, without tranches.
     */
    public function __construct(
        public DerivedCommissionRule $rule,
        public Money $base,
        public Money $commission,
        public array $details = [],
    ) {}
}
