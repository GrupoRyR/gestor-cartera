<?php

namespace App\Modules\Gerencia\Domain\Collections;

use Carbon\CarbonImmutable;

/**
 * Bank collections of one calendar day, all companies together.
 */
final readonly class DayTotal
{
    public function __construct(
        public CarbonImmutable $date,
        public float $total,
    ) {}
}
