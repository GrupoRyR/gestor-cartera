<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Percentage;
use InvalidArgumentException;

/**
 * One row of the collection tranche table (§5.3): payments made between
 * $fromDays and $toDays after the invoice date (both inclusive) earn $share
 * of the code's collection rate.
 */
final readonly class CollectionTranche
{
    /**
     * @param  int|null  $toDays  Null for the open-ended last tranche ("126 or more").
     *
     * @throws InvalidArgumentException when the days are negative or reversed, or the share exceeds 100 %.
     */
    public function __construct(
        public int $fromDays,
        public ?int $toDays,
        public Percentage $share,
    ) {
        if ($fromDays < 0) {
            throw new InvalidArgumentException("A tranche cannot start at negative days, got {$fromDays}.");
        }

        if ($toDays !== null && $toDays < $fromDays) {
            throw new InvalidArgumentException("A tranche cannot end at {$toDays} days before it starts at {$fromDays}.");
        }

        if ($share->compareTo(Percentage::full()) > 0) {
            throw new InvalidArgumentException("A tranche share cannot exceed 100 %, got {$share->percent()} %.");
        }
    }

    public function covers(int $days): bool
    {
        return $days >= $this->fromDays && ($this->toDays === null || $days <= $this->toDays);
    }
}
