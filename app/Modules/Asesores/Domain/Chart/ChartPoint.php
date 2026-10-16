<?php

namespace App\Modules\Asesores\Domain\Chart;

use Carbon\CarbonImmutable;

/**
 * A point of a line chart: where it sits on the drawing and what it stands for.
 */
final readonly class ChartPoint
{
    public function __construct(
        public float $x,
        public float $y,
        public CarbonImmutable $date,
        public float $value,
    ) {}
}
