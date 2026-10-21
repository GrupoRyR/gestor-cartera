<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Comisiones\Infrastructure\Settlement\InputChanges;
use App\Modules\Comisiones\Infrastructure\Settlement\UserNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The settlement of a period (§6, §7, §9.7), comisiones.periodos.*:
 *
 * - GET /comisiones/liquidacion: the menu entry "Liquidación del mes"; goes
 *   to the month before the current one (the month being settled once it
 *   ends), unless the month in course is already open. A draft left open
 *   in another month never holds the entry;
 * - GET /comisiones/periodos/{periodo}?version=N: status, version and
 *   revision, who calculated, approved and closed it, totals, alerts with
 *   their justifications, the approval form and the versions of the period.
 */
final class SettlementController
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly UserNames $names,
    ) {}

    public function current(): RedirectResponse
    {
        $current = PeriodRoute::current();
        $currentOpen = SettlementRecord::query()
            ->where('periodo', $current->key())
            ->where('estado', '!=', SettlementStatus::Closed->value)
            ->exists();

        $period = $currentOpen ? $current : $current->previous();

        return redirect()->route('comisiones.periodos.show', $period->key());
    }

    public function show(Request $request, ApprovalGuard $guard, InputChanges $changes, string $periodo): View
    {
        $period = PeriodRoute::period($periodo);
        $versions = $this->settlements->versions($period);

        $selected = SelectedSettlement::readable($request, $period, $this->settlements);

        if ($selected === null) {
            return view('comisiones::periodos.show', [
                'period' => $period,
                'selected' => new SelectedSettlement($period, null, null),
                'versions' => $versions,
                'names' => [],
                'corrupt' => true,
                'approval' => null,
            ]);
        }

        $settlement = $selected->settlement;
        $names = $this->names->of([
            ...array_map(fn (SettlementRecord $row): string => $row->calculado_por, $versions),
            $settlement?->approvedBy,
            $settlement?->closedBy,
        ]);

        $user = $request->user();
        $approval = null;

        if ($settlement !== null && $selected->isLatest() && $settlement->status === SettlementStatus::Draft
            && $user instanceof User && $user->can('comisiones.approve')) {
            $reasons = $guard->reasons($period, $user);
            $isAnalyst = mb_strtolower(trim($settlement->calculatedBy)) === mb_strtolower(trim($user->email));

            if ($changes->since($period, $settlement->calculatedAt)) {
                $reasons[] = __('comisiones::liquidacion.errores.entradas_cambiaron', [
                    'periodo' => $period->key(),
                    'revision' => $settlement->revision,
                    'paso' => __('comisiones::liquidacion.errores.pasos.aprobar'),
                ]);
            }

            $approval = [
                'reasons' => $isAnalyst ? [__('comisiones::liquidacion.aprobacion.mismo_analista'), ...$reasons] : $reasons,
            ];
        }

        return view('comisiones::periodos.show', [
            'period' => $period,
            'selected' => $selected,
            'versions' => $versions,
            'names' => $names,
            'corrupt' => false,
            'approval' => $approval,
        ]);
    }
}
