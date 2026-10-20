<?php

namespace App\Modules\Comisiones\Infrastructure\Dashboard;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;

/**
 * The manual captures of a period still waiting for an approval (§6 step 3,
 * §7.4): only approved captures enter a calculation, so a pending one is
 * left out of the settlement until someone reviews it.
 */
final class PendingCaptures
{
    /**
     * @return array{obr: int, ajustes: int, cruces: int, reasignaciones: int}
     */
    public function of(Period $period): array
    {
        $start = $period->start()->format('Y-m-d');
        $end = $period->end()->format('Y-m-d');
        $pending = CaptureStatus::Pending->value;

        return [
            'obr' => ObrRecord::query()->where('periodo', $period->key())->where('estado', $pending)->count(),
            'ajustes' => AdjustmentRecord::query()->where('periodo', $period->key())->where('estado', $pending)->count(),
            'cruces' => CrossingRecord::query()->where('origen', CrossingRecord::MANUAL)
                ->whereBetween('fecha_cruce', [$start, $end])->where('estado', $pending)->count(),
            'reasignaciones' => ReassignmentRecord::query()->where('estado', $pending)
                ->whereIn('clave_cruce', CrossingRecord::query()->select('clave')->whereBetween('fecha_cruce', [$start, $end]))
                ->count(),
        ];
    }
}
