<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Comisiones\Infrastructure\Settlement\UserNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The settlement of a period (§6, §7, §9.7), comisiones.periodos.*:
 *
 * - GET /comisiones/liquidacion: the menu entry "Liquidación del mes"; goes
 *   to the latest period still open, or else to the month before the
 *   current one (the month being settled once it ends);
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
        $open = SettlementRecord::query()
            ->where('estado', '!=', SettlementStatus::Closed->value)
            ->max('periodo');

        $period = is_string($open) ? Period::fromString($open) : PeriodRoute::current()->previous();

        return redirect()->route('comisiones.periodos.show', $period->key());
    }

    public function show(Request $request, ApprovalGuard $guard, string $periodo): View
    {
        $period = PeriodRoute::period($periodo);
        $versions = SettlementRecord::query()->where('periodo', $period->key())->orderBy('version')->get();

        try {
            $selected = SelectedSettlement::from($request, $period, $this->settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

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
            ...$versions->pluck('calculado_por')->all(),
            $settlement?->approvedBy,
            $settlement?->closedBy,
        ]);

        $user = $request->user();
        $approval = null;

        if ($settlement !== null && $selected->isLatest() && $settlement->status === SettlementStatus::Draft
            && $user instanceof User && $user->can('comisiones.approve')) {
            $reasons = $guard->reasons($period, $user);
            $isAnalyst = mb_strtolower(trim($settlement->calculatedBy)) === mb_strtolower(trim($user->email));

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
