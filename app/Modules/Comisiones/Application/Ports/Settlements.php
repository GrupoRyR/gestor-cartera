<?php

namespace App\Modules\Comisiones\Application\Ports;

use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\StaleSettlement;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * The stored settlements, every version of every period (§4 "Liquidacion").
 * Implemented by the infrastructure.
 */
interface Settlements
{
    /** The highest version of the period, or null when it was never calculated. */
    public function latest(Period $period): ?Settlement;

    /**
     * Stores the version, with its result, its revision and the parameters
     * frozen in it. An open version (draft or approved) replaces the stored
     * copy of the same version; every other version is kept. A closed version
     * is never overwritten (§6 step 7).
     *
     * Optimistic check: never replace a stored version with a higher revision
     * than the one being saved. A recalculation saves the next revision and an
     * approval or a closing the same one, so only a save that started from an
     * older read (another user recalculated meanwhile) is refused. Check and
     * write atomically, for example `UPDATE … WHERE revision <= ?` on the
     * version row.
     *
     * @throws StaleSettlement when the stored copy of the version has a higher revision.
     */
    public function save(Settlement $settlement): void;
}
