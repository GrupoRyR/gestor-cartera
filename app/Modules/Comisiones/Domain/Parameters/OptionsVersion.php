<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Validity;

/**
 * One version of the settlement options, so an answer to an open point can
 * change from a given month without touching closed settlements.
 */
final readonly class OptionsVersion
{
    public function __construct(
        public Validity $validity,
        public SettlementOptions $options,
    ) {}
}
