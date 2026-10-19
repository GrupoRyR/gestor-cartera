<?php

namespace App\Modules\Asesores\Application\Portfolio;

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\PortfolioTotals;

/**
 * Everything the dashboard shows, read in one pass.
 */
final readonly class Overview
{
    /**
     * @param  list<ClientSummary>  $priority  Overdue the longest, over 90 days.
     * @param  list<PortfolioTotals>  $history  One entry per cutoff date, oldest first.
     * @param  list<Advisor>  $advisors  The advisors the user can look at.
     * @param  Advisor|null  $selected  The advisor chosen in the filter, if the user can see them.
     */
    public function __construct(
        public PortfolioTotals $totals,
        public array $priority,
        public array $history,
        public array $advisors,
        public ?Advisor $selected,
    ) {}
}
