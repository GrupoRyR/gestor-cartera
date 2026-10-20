<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Segregation of duties on top of the engine's "approver ≠ analyst" (§10):
 * whoever loaded captures of the period (budget, OBR, adjustments, manual
 * crossings, reassignments of its crossings) or added a parameter version
 * in force in it never approves its settlement. A capture counts whatever
 * its review state: loading it is what matters.
 *
 * The approval screen calls assertMayApprove() before ApproveSettlement, and
 * the capture screens call mayReview() before approving or rejecting one.
 */
final class ApprovalGuard
{
    /**
     * Why the user may not approve the period, one Spanish sentence each;
     * empty when they may.
     *
     * @return list<string>
     */
    public function reasons(Period $period, User $approver): array
    {
        $key = $period->key();
        $start = $period->start()->format('Y-m-d');
        $end = $period->end()->format('Y-m-d');
        $id = $approver->id;

        $checks = [
            'presupuesto' => BudgetRecord::query()->where('periodo', $key)
                ->where(fn (Builder $query) => $query->where('created_by', $id)->orWhere('updated_by', $id)),
            'obr' => ObrRecord::query()->where('periodo', $key)->where('created_by', $id),
            'ajustes' => AdjustmentRecord::query()->where('periodo', $key)->where('created_by', $id),
            'cruces' => CrossingRecord::query()->where('origen', CrossingRecord::MANUAL)
                ->whereBetween('fecha_cruce', [$start, $end])->where('created_by', $id),
            'reasignaciones' => ReassignmentRecord::query()->where('created_by', $id)
                ->whereIn('clave_cruce', CrossingRecord::query()->select('clave')->whereBetween('fecha_cruce', [$start, $end])),
            'parametros' => ParameterRecord::query()->where('created_by', $id)
                ->where('vigente_desde', '<=', $end)
                ->where(fn (Builder $query) => $query->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $start)),
        ];

        $reasons = [];

        foreach ($checks as $reason => $query) {
            if ($query->exists()) {
                $reasons[] = __("comisiones::segregacion.{$reason}", ['periodo' => $key]);
            }
        }

        return $reasons;
    }

    /**
     * @throws ApprovalRefused when the user loaded data or changed parameters of the period.
     */
    public function assertMayApprove(Period $period, User $approver): void
    {
        $reasons = $this->reasons($period, $approver);

        if ($reasons !== []) {
            throw new ApprovalRefused(__('comisiones::segregacion.rechazada', ['periodo' => $period->key()]), $reasons);
        }
    }

    /**
     * Whether the user may approve or reject a capture: only someone other
     * than the user who created it.
     */
    public function mayReview(Model $capture, User $reviewer): bool
    {
        $creator = $capture->getAttribute('created_by');

        return $creator === null || (int) $creator !== $reviewer->id;
    }
}
