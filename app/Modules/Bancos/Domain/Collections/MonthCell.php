<?php

namespace App\Modules\Bancos\Domain\Collections;

use Carbon\CarbonImmutable;

/**
 * One weekday of a row of the monthly grid.
 */
final readonly class MonthCell
{
    /**
     * @param  CarbonImmutable  $date  The weekday, even when it is outside the month.
     * @param  float  $amount  Pesos credited from the day after the previous business day up to this one; 0 when Outside or Holiday.
     */
    public function __construct(
        public CarbonImmutable $date,
        public MonthCellState $state,
        public float $amount,
    ) {}

    public function isLoaded(): bool
    {
        return $this->state === MonthCellState::Loaded;
    }
}
