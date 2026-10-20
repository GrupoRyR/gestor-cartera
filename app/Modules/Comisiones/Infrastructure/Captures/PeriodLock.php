<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;

/**
 * Whether the captures of a period are frozen (§6 step 6): a period is
 * locked while its latest settlement version is closed. Registering a
 * complementary settlement adds an open version, which opens the period
 * again for the corrections it needs (§6 step 7).
 *
 * Reads the status column only; nothing is unserialized.
 */
final class PeriodLock
{
    public function isLocked(Period $period): bool
    {
        $status = SettlementRecord::query()
            ->where('periodo', $period->key())
            ->orderByDesc('version')
            ->value('estado');

        return $status === SettlementStatus::Closed->value;
    }

    /** The Spanish notice the capture screens show and the refused writes flash. */
    public static function message(): string
    {
        return __('comisiones::comun.periodo_cerrado');
    }
}
