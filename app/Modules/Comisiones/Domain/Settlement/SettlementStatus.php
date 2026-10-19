<?php

namespace App\Modules\Comisiones\Domain\Settlement;

/**
 * Where a settlement is in the monthly flow (§6): a draft is recalculated as
 * often as needed, a second user approves it and the system closes it. A
 * closed settlement is never changed; corrections are new versions.
 */
enum SettlementStatus: string
{
    /** Calculated and open: it can be recalculated (§6 step 4). */
    case Draft = 'borrador';

    /** Approved by a user other than the analyst (§6 step 5); still open. */
    case Approved = 'aprobada';

    /** Closed and frozen (§6 step 6): only a complementary version corrects it. */
    case Closed = 'cerrada';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Approved => 'Aprobada',
            self::Closed => 'Cerrada',
        };
    }
}
