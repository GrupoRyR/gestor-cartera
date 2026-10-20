<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Requests\StoreAdjustmentRequest;
use App\Modules\Comisiones\Http\Requests\StoreObrRequest;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureKind;
use App\Modules\Comisiones\Infrastructure\Captures\ManualCaptures;
use App\Modules\Comisiones\Infrastructure\Captures\PeriodLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The forms of «Ajustes y cruces» (§7.4), POST under /comisiones/ajustes
 * (comisiones.capture): each registers a pending capture with its support
 * in the tab of its period, unless that period is closed (PeriodLock).
 */
final class CaptureStoreController
{
    public function __construct(
        private readonly ManualCaptures $captures,
        private readonly PeriodLock $lock,
    ) {}

    public function obr(StoreObrRequest $request): RedirectResponse
    {
        return $this->store($request, CaptureKind::Obr, $request->period(), $request->columns(), $request->support());
    }

    public function adjustment(StoreAdjustmentRequest $request): RedirectResponse
    {
        return $this->store($request, CaptureKind::Adjustment, $request->period(), $request->columns(), $request->support());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(Request $request, CaptureKind $kind, Period $period, array $attributes, UploadedFile $support): RedirectResponse
    {
        $back = redirect()->route('comisiones.ajustes.index', ['pestana' => $kind->tab(), 'periodo' => $period->key()]);

        if ($this->lock->isLocked($period)) {
            return $back->with('error', PeriodLock::message());
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->captures->create($kind, $attributes, $support, $user, $period->key());

        return $back->with('status', __('comisiones::ajustes.mensajes.creado', [
            'que' => __('comisiones::ajustes.mensajes.que.'.$kind->value),
            'periodo' => $period->key(),
        ]));
    }
}
