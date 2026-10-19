<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * The settlement was recalculated after the user last saw it (§6 step 5,
 * §10): the approver names the revision they reviewed, and approving a later
 * one would sign off figures nobody reviewed. The stored versions refuse a
 * save that would go back to an older revision for the same reason. Reload
 * the settlement and review it again.
 */
final class StaleSettlement extends DomainException
{
    /**
     * @param  int  $expectedRevision  The revision the user worked on: the one the approver reviewed.
     * @param  int  $currentRevision  The revision the settlement is at now.
     */
    public function __construct(
        Period $period,
        int $version,
        private readonly int $expectedRevision,
        private readonly int $currentRevision,
    ) {
        parent::__construct(sprintf(
            'Version %d of the %s settlement is at revision %d, not at the reviewed revision %d: it was recalculated meanwhile, so review it again.',
            $version,
            $period->key(),
            $currentRevision,
            $expectedRevision,
        ));
    }

    /** The revision the user worked on: the one the approver reviewed. */
    public function expectedRevision(): int
    {
        return $this->expectedRevision;
    }

    /** The revision the settlement is at now. */
    public function currentRevision(): int
    {
        return $this->currentRevision;
    }
}
