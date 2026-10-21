<?php

namespace App\Modules\Comisiones\Infrastructure\Controls;

use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;

/**
 * The Ingesta loads of one dataset staged for a period (§9.4 in this
 * module): how many arrived, their rows and the last one.
 */
final readonly class LoadSummary
{
    public function __construct(
        public Dataset $dataset,
        public int $loads,
        public int $rows,
        public int $inserted,
        public int $updated,
        public ?CarbonImmutable $lastReceived,
    ) {}
}
