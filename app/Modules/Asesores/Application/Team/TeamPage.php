<?php

namespace App\Modules\Asesores\Application\Team;

use Carbon\CarbonImmutable;

/**
 * What the team screen shows: one row per advisor, the biggest overdue first,
 * and the sums of the team.
 */
final readonly class TeamPage
{
    /**
     * @param  list<TeamRow>  $rows
     * @param  CarbonImmutable|null  $cutoff  The oldest cutoff among the rows: the figures are as old as that.
     */
    public function __construct(
        public array $rows,
        public int $clients,
        public float $total,
        public float $overdue,
        public ?CarbonImmutable $cutoff,
    ) {}
}
