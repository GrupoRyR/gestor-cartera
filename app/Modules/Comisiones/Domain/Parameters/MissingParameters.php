<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * The parameter book cannot settle a period because a required parameter has
 * no version in force (e.g. no collection tranche table).
 */
final class MissingParameters extends DomainException
{
    public static function trancheTable(Period $period): self
    {
        return new self("No collection tranche table is in force for period {$period->key()}.");
    }
}
