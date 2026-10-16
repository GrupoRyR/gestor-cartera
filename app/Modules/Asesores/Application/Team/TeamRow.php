<?php

namespace App\Modules\Asesores\Application\Team;

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\PortfolioTotals;

/**
 * One advisor of the team with the figures of their portfolio.
 */
final readonly class TeamRow
{
    /**
     * @param  int  $broken  Commitments of the advisor that were not paid on the promised day.
     * @param  float  $width  Length of the overdue bar against the biggest of the team, 0 to 100.
     */
    public function __construct(
        public Advisor $advisor,
        public PortfolioTotals $totals,
        public int $broken,
        public float $width,
    ) {}
}
