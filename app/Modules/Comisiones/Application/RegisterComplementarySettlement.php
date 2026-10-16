<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Domain\Settlement\InvalidTransition;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Corrects a closed period (§6 step 7): settles it again on the current data
 * and parameters as a new draft version with its reason, which then goes
 * through approval and closing. The closed version stays stored as it was.
 */
final class RegisterComplementarySettlement
{
    public function __construct(
        private readonly SettlementInputs $inputs,
        private readonly CommissionParameters $parameters,
        private readonly Settlements $settlements,
    ) {}

    /**
     * @throws SettlementNotFound when the period was never calculated.
     * @throws InvalidTransition when the latest version is still open: recalculate it instead.
     * @throws InvalidArgumentException when the reason is blank.
     * @throws MissingParameters when no tranche table is in force in the period.
     */
    public function handle(Period $period, string $analyst, string $reason, DateTimeImmutable $at): Settlement
    {
        $closed = $this->settlements->latest($period) ?? throw SettlementNotFound::forPeriod($period);
        $result = (new SettlementCalculator)->calculate(
            $this->inputs->forPeriod($period),
            $this->parameters->book()->forPeriod($period),
        );
        $complementary = $closed->complement($reason, $result, $analyst, $at);

        $this->settlements->save($complementary);

        return $complementary;
    }
}
