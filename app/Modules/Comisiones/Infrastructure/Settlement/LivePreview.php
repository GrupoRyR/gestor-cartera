<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * The month to date of the dashboard (§6 step 1, §7.1): the engine run on
 * what is staged today, with the parameters in force, without storing
 * anything. It is what "Calcular liquidación" would give right now.
 */
final class LivePreview
{
    public function __construct(
        private readonly SettlementInputs $inputs,
        private readonly CommissionParameters $parameters,
    ) {}

    /**
     * @throws MissingParameters when no tranche table is in force in the period.
     */
    public function calculate(Period $period): SettlementResult
    {
        return (new SettlementCalculator)->calculate(
            $this->inputs->forPeriod($period),
            $this->parameters->book()->forPeriod($period),
        );
    }
}
