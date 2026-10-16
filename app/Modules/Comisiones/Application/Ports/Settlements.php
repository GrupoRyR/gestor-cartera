<?php

namespace App\Modules\Comisiones\Application\Ports;

use App\Modules\Comisiones\Domain\Settlement\Settlement;
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
     * Stores the version, with its result and the parameters frozen in it.
     * An open version (draft or approved) replaces the stored copy of the
     * same version; every other version is kept. A closed version is never
     * overwritten (§6 step 7).
     */
    public function save(Settlement $settlement): void;
}
