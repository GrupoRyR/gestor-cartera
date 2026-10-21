<?php

namespace App\Modules\Bancos\Domain\Collections;

use Carbon\CarbonImmutable;

/**
 * One row of the monthly grid: a Monday-to-Friday week with at least one
 * weekday in the month.
 */
final readonly class MonthWeek
{
    /**
     * @param  CarbonImmutable  $start  First weekday of the week inside the month.
     * @param  CarbonImmutable  $end  Last weekday of the week inside the month.
     * @param  int  $businessDays  Weekdays of the week inside the month that are not holidays.
     * @param  list<MonthCell>  $cells  Monday to Friday.
     * @param  float  $total  Pesos of the row: the sum of its cells.
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $businessDays,
        public array $cells,
        public float $total,
    ) {}

    /**
     * "05 oct. – 09 oct.", or a single day when the week has only one in the month.
     */
    public function label(): string
    {
        $first = $this->start->locale('es')->isoFormat('DD MMM');

        if ($this->start->toDateString() === $this->end->toDateString()) {
            return $first;
        }

        return $first.' – '.$this->end->locale('es')->isoFormat('DD MMM');
    }

    /**
     * Whether any day of the row has its figure already.
     */
    public function hasFigures(): bool
    {
        foreach ($this->cells as $cell) {
            if ($cell->isLoaded()) {
                return true;
            }
        }

        return false;
    }
}
