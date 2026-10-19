<?php

namespace App\Modules\Bancos\Domain\Collections;

use Carbon\CarbonImmutable;

/**
 * One day of the weekly report.
 */
final readonly class WeekDay
{
    /**
     * @param  bool  $loaded  Whether the statements of the day can be here already (it is not today or later).
     * @param  float  $total  Pesos that came in that day.
     * @param  float|null  $cumulative  Pesos so far this week, up to and including the day; null for a day not loaded.
     */
    public function __construct(
        public CarbonImmutable $date,
        public bool $loaded,
        public float $total,
        public ?float $cumulative,
    ) {}
}
