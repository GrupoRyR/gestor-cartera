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

    /**
     * The lines a derived or director commission counts (§5.5, §5.6): the
     * payments, plus the crossings only when the options say so (§14.3).
     *
     * @return list<CollectionDetail>
     */
    public function lines(bool $withCrossings): array
    {
        if ($withCrossings) {
            return $this->details;
        }

        return array_values(array_filter($this->details, fn (CollectionDetail $detail) => ! $detail->isCrossing()));
    }

    /** The paid base of those lines. */
    public function collectedOf(bool $withCrossings): Money
    {
        return Money::sum(array_map(fn (CollectionDetail $detail) => $detail->paidBase, $this->lines($withCrossings)));
    }
}
