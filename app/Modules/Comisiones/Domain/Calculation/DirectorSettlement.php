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
     * @param  int|null  $paidWithCode  The code whose total carries this commission when the options
     *                                  place it on the associated code (§14.4); null when the
     *                                  director is paid for it as a role of its own.
     */
    public function __construct(
        public DirectorTeam $team,
        public array $rows,
        public Money $sales,
        public Money $collected,
        public Money $salesCommission,
        public Money $collectionCommission,
        public Money $total,
        public ?int $paidWithCode = null,
    ) {}
}
