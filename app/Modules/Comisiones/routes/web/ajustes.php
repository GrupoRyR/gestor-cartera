<?php

use App\Modules\Comisiones\Http\Controllers\CapturesController;
use App\Modules\Comisiones\Http\Controllers\CaptureStoreController;
use App\Modules\Comisiones\Http\Controllers\CaptureSupportController;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureKind;
use Illuminate\Support\Facades\Route;

// «Ajustes y cruces» (§7.4): one tab per capture kind (?periodo=YYYY-MM),
// the forms that register them and their supports. {tipo} is a
// CaptureKind value: obr, ajuste, cruce or reasignacion.

Route::middleware(['auth', 'can:comisiones.view'])->prefix('ajustes')->name('ajustes.')->group(function () {
    Route::get('{pestana?}', [CapturesController::class, 'index'])->whereIn('pestana', CaptureKind::tabs())->name('index');
    Route::get('{tipo}/{captura}/soporte', CaptureSupportController::class)
        ->whereIn('tipo', CaptureKind::values())->whereNumber('captura')->name('soporte');
});

Route::middleware(['auth', 'can:comisiones.capture'])->prefix('ajustes')->name('ajustes.')->group(function () {
    Route::post('obr', [CaptureStoreController::class, 'obr'])->name('obr.store');
    Route::post('reintegros', [CaptureStoreController::class, 'adjustment'])->name('reintegros.store');
    Route::post('cruces', [CaptureStoreController::class, 'crossing'])->name('cruces.store');
    Route::post('reasignaciones', [CaptureStoreController::class, 'reassignment'])->name('reasignaciones.store');
});
