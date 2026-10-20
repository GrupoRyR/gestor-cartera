<?php

namespace App\Modules\Comisiones\Infrastructure\Dashboard;

use App\Modules\Comisiones\Domain\Calculation\CodeSettlement;

/**
 * One bar of the compliance chart: the code and where its bar ends and its
 * minimum sits on the shared axis, as a percentage of the track.
 */
final readonly class ComplianceBar
{
    /**
     * @param  float  $width  Bar length, 0–100 % of the track.
     * @param  float|null  $minimumAt  Position of the minimum, 0–100 % of the track; null without a minimum.
     */
    public function __construct(
        public CodeSettlement $code,
        public float $width,
        public ?float $minimumAt,
    ) {}
}
