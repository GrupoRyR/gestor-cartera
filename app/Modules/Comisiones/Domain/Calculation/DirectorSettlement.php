<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The commission of a channel director over the team's sales and
 * collections (§5.6).
 */
final readonly class DirectorSettlement
{
    /**
     * @param  list<DirectorTeamRow>  $rows  One per member code.
     * @param  Money  $salesCommission  Team sales × rate, rounded to the peso.
     * @param  Money  $collectionCommission  Team collections × rate, rounded to the peso.
     * @param  Money  $total  Both commissions.
     */
    public function __construct(
        public DirectorTeam $team,
        public array $rows,
        public Money $sales,
        public Money $collected,
        public Money $salesCommission,
        public Money $collectionCommission,
        public Money $total,
    ) {}
}
