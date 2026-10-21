<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * The seller codes a capture of the period may name: the regular and the
 * separate codes with parameters in force (§7.2). Control codes (80, 100)
 * never earn a commission, so they take no budget, OBR sale, adjustment or
 * crossing.
 */
final class CodesInForce
{
    public function __construct(
        private readonly CommissionParameters $parameters,
    ) {}

    /**
     * Ordered by code; empty when the period has no parameters at all.
     *
     * @return list<SellerCodeRule>
     */
    public function of(Period $period): array
    {
        try {
            $codes = $this->parameters->book()->forPeriod($period)->codes();
        } catch (MissingParameters) {
            return [];
        }

        $codes = array_values(array_filter($codes, fn (SellerCodeRule $rule): bool => $rule->scope !== CodeScope::Control));
        usort($codes, fn (SellerCodeRule $a, SellerCodeRule $b): int => $a->code <=> $b->code);

        return $codes;
    }

    /**
     * Code => "14 · Mónica Rivera", for a select.
     *
     * @return array<int, string>
     */
    public function options(Period $period): array
    {
        $options = [];

        foreach ($this->of($period) as $rule) {
            $options[$rule->code] = "{$rule->code} · {$rule->name}";
        }

        return $options;
    }
}
