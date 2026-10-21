<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Controls\PeriodControls;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /comisiones/periodos/{periodo}/controles?version=N
 * (comisiones.periodos.controles, comisiones.view): the controls of §9 for
 * a settlement version (§7.8), each with its status and its figures. A
 * period never calculated asks to calculate it.
 */
final class ControlsController
{
    public function __invoke(Request $request, EloquentSettlements $settlements, PeriodControls $controls, string $periodo): View|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);

        try {
            $selected = SelectedSettlement::from($request, $period, $settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        return view('comisiones::controles.index', [
            'period' => $period,
            'selected' => $selected,
            'controls' => $selected->settlement === null ? null : $controls->of($selected->settlement),
        ]);
    }
}
