<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementLocked;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;

/**
 * "Calcular liquidación" (§6 step 4): settles the period on the parameters
 * in force for it and stores the draft. The first time it creates version 1;
 * while the latest version is open (draft or approved) it recalculates that
 * version, which goes back to draft. A closed period is refused before the
 * inputs and parameters are read, so the refusal never runs the engine.
 */
final class CalculateSettlement
{
    public function __construct(
        private readonly SettlementInputs $inputs,
        private readonly CommissionParameters $parameters,
        private readonly Settlements $settlements,
    ) {}

    /**
     * @throws SettlementLocked when the latest version is closed: register a complementary settlement.
     * @throws MissingParameters when no tranche table is in force in the period.
     */
    public function handle(Period $period, string $analyst, DateTimeImmutable $at): Settlement
    {
        $previous = $this->settlements->latest($period);

        if ($previous !== null && ! $previous->isOpen()) {
            throw SettlementLocked::closed($previous->period, $previous->version);
        }

        $result = (new SettlementCalculator)->calculate(
            $this->inputs->forPeriod($period),
            $this->parameters->book()->forPeriod($period),
        );

        $settlement = $previous === null
            ? Settlement::calculate($result, $analyst, $at)
            : $previous->recalculate($result, $analyst, $at);

        $this->settlements->save($settlement);

        return $settlement;
    }
}
