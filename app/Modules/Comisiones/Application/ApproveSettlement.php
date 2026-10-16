<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Settlement\InvalidTransition;
use App\Modules\Comisiones\Domain\Settlement\SegregationOfDuties;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\UnresolvedAlerts;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The approver signs off the latest draft of the period (§6 step 5), with a
 * justification for every blocking alert (§9.7).
 */
final class ApproveSettlement
{
    public function __construct(
        private readonly Settlements $settlements,
    ) {}

    /**
     * @param  array<string, string>  $justifications  Alert key ("codigo_desconocido:99") → justification.
     *
     * @throws SettlementNotFound when the period was never calculated.
     * @throws InvalidTransition when the latest version is not a draft.
     * @throws SegregationOfDuties when the approver calculated it.
     * @throws UnresolvedAlerts when a blocking alert has no justification.
     * @throws InvalidArgumentException when a key is not a blocking alert of the result.
     */
    public function handle(Period $period, string $approver, DateTimeImmutable $at, array $justifications = []): Settlement
    {
        $draft = $this->settlements->latest($period) ?? throw SettlementNotFound::forPeriod($period);
        $approved = $draft->approve($approver, $at, $justifications);

        $this->settlements->save($approved);

        return $approved;
    }
}
