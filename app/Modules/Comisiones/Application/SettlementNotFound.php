<?php

namespace App\Modules\Comisiones\Application;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * A step of the monthly flow asked for a period that was never calculated.
 */
final class SettlementNotFound extends DomainException
{
    public static function forPeriod(Period $period): self
    {
        return new self("There is no settlement of {$period->key()}; calculate it first.");
    }
}
