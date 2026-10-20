<?php

namespace App\Modules\Gerencia\Application\Collections;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Domain\Collections\CollectionSeries;
use App\Modules\Gerencia\Domain\Collections\DayTotal;

/**
 * Bank collections (recaudo) of the companies in the user's scope over a
 * period.
 */
final readonly class CollectionsReport
{
    /**
     * @param  list<DailyCollection>  $daily  By date, then company.
     * @param  list<WeeklyCollection>  $weekly  By week start, then company.
     */
    public function __construct(
        public array $daily,
        public array $weekly,
    ) {}

    /**
     * Collections of every company added up for each day, ascending.
     *
     * @return list<DayTotal>
     */
    public function dayTotals(): array
    {
        return CollectionSeries::daily($this->daily);
    }

    /**
     * Total collected in the period, of one company or of all of them.
     */
    public function total(?CompanyCode $company = null): float
    {
        $total = 0.0;

        foreach ($this->daily as $day) {
            if ($company === null || $day->company === $company) {
                $total += $day->total;
            }
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->daily === [] && $this->weekly === [];
    }
}
