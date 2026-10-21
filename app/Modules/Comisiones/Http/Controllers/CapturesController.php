<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureKind;
use App\Modules\Comisiones\Infrastructure\Captures\CodesInForce;
use App\Modules\Comisiones\Infrastructure\Captures\CrossingChoices;
use App\Modules\Comisiones\Infrastructure\Captures\PeriodLock;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /comisiones/ajustes/{pestana?}?periodo=YYYY-MM (comisiones.ajustes.index,
 * comisiones.view): the manual captures of a period (§7.4), one tab per
 * kind (obr, reintegros, cruces, reasignaciones), each its own URL. A tab
 * lists its captures with their review state and support, and offers the
 * form to register one to whoever holds comisiones.capture while the
 * period is open.
 */
final class CapturesController
{
    public function index(Request $request, CodesInForce $codes, CrossingChoices $choices, PeriodLock $lock, ?string $pestana = null): View
    {
        $period = PeriodRoute::fromQuery($request->query('periodo'), PeriodRoute::current());
        $kind = CaptureKind::fromTab($pestana ?? CaptureKind::Obr->tab());
        $pending = [];

        foreach (CaptureKind::cases() as $each) {
            $pending[$each->tab()] = $each->inPeriod($period)->where('estado', CaptureStatus::Pending->value)->count();
        }

        $captures = $kind->inPeriod($period)->get();
        $reassigning = $kind === CaptureKind::Reassignment;

        return view('comisiones::ajustes.index', [
            'period' => $period,
            'kind' => $kind,
            'captures' => $captures,
            'pending' => $pending,
            'locked' => $lock->isLocked($period),
            'codes' => $codes->options($period),
            'canCapture' => $request->user()?->can('comisiones.capture') ?? false,
            // The reassignments tab: the crossings to offer, and the ones its rows name.
            'choices' => $reassigning ? $choices->options($period) : [],
            'crossings' => $reassigning
                ? CrossingRecord::query()
                    ->whereIn('clave', $captures->pluck('clave_cruce')->filter()->all())
                    ->get()
                    ->keyBy('clave')
                    ->all()
                : [],
        ]);
    }
}
