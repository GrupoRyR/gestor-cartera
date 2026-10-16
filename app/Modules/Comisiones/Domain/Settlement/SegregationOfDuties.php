<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * The approver must be a user other than the analyst who calculated the
 * settlement (§10, segregation of duties).
 */
final class SegregationOfDuties extends DomainException
{
    public static function sameAnalyst(string $user, Period $period, int $version): self
    {
        return new self(sprintf(
            '%s calculated version %d of the %s settlement, so another user must approve it.',
            $user,
            $version,
            $period->key(),
        ));
    }
}
