<?php

namespace App\Modules\Cartera\Domain\Dashboard;

/**
 * One band of the overdue debt: its amount and its percentage (0 to 100) of
 * everything that is overdue.
 */
final readonly class AgingSlice
{
    public function __construct(
        public AgingBand $band,
        public float $amount,
        public float $share,
    ) {}
}
