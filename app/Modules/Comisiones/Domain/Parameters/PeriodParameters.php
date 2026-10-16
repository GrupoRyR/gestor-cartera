<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Period;
use InvalidArgumentException;

/**
 * The parameters in force for one period: the frozen snapshot a settlement
 * stores, so recalculating or reprinting it never depends on later changes
 * to the parameter book (§4 "LiquidacionDetalle…" with frozen parameters).
 */
final readonly class PeriodParameters
{
    /** @var array<int, SellerCodeRule> Keyed and sorted by code. */
    private array $codes;

    /**
     * @param  array<int, SellerCodeRule>  $codes  Re-keyed by code and sorted.
     * @param  list<DirectorTeam>  $teams
     * @param  list<DerivedCommissionRule>  $derived
     *
     * @throws InvalidArgumentException when a code appears twice.
     */
    public function __construct(
        private Period $period,
        array $codes,
        private TrancheTable $tranches,
        private array $teams,
        private array $derived,
        private SettlementOptions $options,
    ) {
        $byCode = [];

        foreach ($codes as $rule) {
            if (isset($byCode[$rule->code])) {
                throw new InvalidArgumentException("Seller code {$rule->code} appears twice in period {$period->key()}.");
            }

            $byCode[$rule->code] = $rule;
        }

        ksort($byCode);

        $this->codes = $byCode;
    }

    public function period(): Period
    {
        return $this->period;
    }

    public function code(int $code): ?SellerCodeRule
    {
        return $this->codes[$code] ?? null;
    }

    /**
     * @return array<int, SellerCodeRule> Keyed and sorted by code.
     */
    public function codes(): array
    {
        return $this->codes;
    }

    public function tranches(): TrancheTable
    {
        return $this->tranches;
    }

    /**
     * @return list<DirectorTeam>
     */
    public function teams(): array
    {
        return $this->teams;
    }

    /**
     * @return list<DerivedCommissionRule>
     */
    public function derived(): array
    {
        return $this->derived;
    }

    public function options(): SettlementOptions
    {
        return $this->options;
    }
}
