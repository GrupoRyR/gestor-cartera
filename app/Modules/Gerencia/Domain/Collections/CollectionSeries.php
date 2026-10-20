<?php

namespace App\Modules\Gerencia\Domain\Collections;

use App\Modules\Core\Contracts\Data\DailyCollection;

final class CollectionSeries
{
    /**
     * Adds the companies of each date together.
     *
     * @param  list<DailyCollection>  $collections
     * @return list<DayTotal> Ascending by date.
     */
    public static function daily(array $collections): array
    {
        /** @var array<string, DayTotal> $days */
        $days = [];

        foreach ($collections as $collection) {
            $key = $collection->date->toDateString();

            $days[$key] = new DayTotal($collection->date, ($days[$key]->total ?? 0.0) + $collection->total);
        }

        ksort($days);

        return array_values($days);
    }
}
