<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\DirectorTeam;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\PeriodParameters;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The commission of each channel director over the whole team (§5.6), on
 * top of the director's own commission:
 *
 *   sales commission      = Σ team sales × rate        (rounded to the peso)
 *   collection commission = Σ team collections × rate  (rounded to the peso)
 *
 * Team collections are the paid base of the members' payments; their
 * crossings count only when the options say so (§14.3). A member code
 * without parameters in the period adds nothing.
 */
final class DirectorCommission
{
    public function __construct(
        private readonly PeriodParameters $parameters,
    ) {}

    /**
     * @return list<DirectorSettlement> One per team in force, in parameter order.
     */
    public function calculate(SalesOutcome $sales, CollectionOutcome $collections): array
    {
        $settlements = [];

        foreach ($this->parameters->teams() as $team) {
            $rows = array_map(fn (int $code) => $this->row($code, $sales, $collections), $team->memberCodes);
            $teamSales = Money::sum(array_map(fn (DirectorTeamRow $row) => $row->sales, $rows));
            $collected = Money::sum(array_map(fn (DirectorTeamRow $row) => $row->collected, $rows));
            $salesCommission = $teamSales->times($team->rate)->rounded();
            $collectionCommission = $collected->times($team->rate)->rounded();

            $settlements[] = new DirectorSettlement(
                team: $team,
                rows: $rows,
                sales: $teamSales,
                collected: $collected,
                salesCommission: $salesCommission,
                collectionCommission: $collectionCommission,
                total: $salesCommission->plus($collectionCommission),
                paidWithCode: $this->paidWithCode($team),
            );
        }

        return $settlements;
    }

    private function row(int $code, SalesOutcome $sales, CollectionOutcome $collections): DirectorTeamRow
    {
        $rule = $this->parameters->code($code);

        if ($rule === null) {
            return new DirectorTeamRow($code, "Código {$code}", Money::zero(), Money::zero());
        }

        return new DirectorTeamRow(
            $code,
            $rule->name,
            $sales->forCode($code)->sales,
            $collections->forCode($code)->collectedOf($this->parameters->options()->crossingsInDirectorCollections),
        );
    }

    /**
     * Where the team commission is paid (§14.4): with the director's own
     * code (53, 50) when the options place it there and that code has
     * parameters in the period. Otherwise, so the director never loses it,
     * the team is paid as a role of its own (null).
     */
    private function paidWithCode(DirectorTeam $team): ?int
    {
        $code = $team->associatedCode;

        if ($this->parameters->options()->directorPlacement !== DirectorPlacement::AssociatedCode || $code === null) {
            return null;
        }

        return $this->parameters->code($code) === null ? null : $code;
    }
}
