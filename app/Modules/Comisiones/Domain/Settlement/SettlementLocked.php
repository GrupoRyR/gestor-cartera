<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use App\Modules\Comisiones\Domain\Shared\Period;
use DomainException;

/**
 * A closed settlement cannot be recalculated: corrections are registered as
 * a complementary settlement, a new version (§6 step 7).
 */
final class SettlementLocked extends DomainException
{
    public static function closed(Period $period, int $version): self
    {
        return new self(sprintf(
            'Version %d of the %s settlement is closed; register a complementary settlement to correct it.',
            $version,
            $period->key(),
        ));
    }
}
