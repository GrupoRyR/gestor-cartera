<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Settlement\InvalidTransition;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;

/**
 * Closes the approved settlement of the period (§6 step 6): the version is
 * frozen, and the PDFs and the payroll file can be generated from it.
 */
final class CloseSettlement
{
    public function __construct(
        private readonly Settlements $settlements,
    ) {}

    /**
     * @throws SettlementNotFound when the period was never calculated.
     * @throws InvalidTransition when the latest version is not approved.
     */
    public function handle(Period $period, string $user, DateTimeImmutable $at): Settlement
    {
        $approved = $this->settlements->latest($period) ?? throw SettlementNotFound::forPeriod($period);
        $closed = $approved->close($user, $at);

        $this->settlements->save($closed);

        return $closed;
    }
}
