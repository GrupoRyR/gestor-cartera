<?php

use App\Modules\Comisiones\Http\Controllers\SettlementActionController;
use App\Modules\Comisiones\Http\Controllers\SettlementController;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use Illuminate\Support\Facades\Route;

// The settlement flow: the period screen and its steps (§6, §7.5, §7.6).
// {periodo} is a month, YYYY-MM (PeriodRoute::PATTERN).

Route::middleware(['auth', 'can:comisiones.view'])->group(function () {
    Route::get('liquidacion', [SettlementController::class, 'current'])->name('liquidacion');

    Route::prefix('periodos/{periodo}')->where(['periodo' => PeriodRoute::PATTERN])->name('periodos.')->group(function () {
        Route::get('/', [SettlementController::class, 'show'])->name('show');
    });
});

Route::middleware('auth')->prefix('periodos/{periodo}')->where(['periodo' => PeriodRoute::PATTERN])->name('periodos.')->group(function () {
    Route::post('calcular', [SettlementActionController::class, 'calculate'])->middleware('can:comisiones.calculate')->name('calcular');
    Route::post('aprobar', [SettlementActionController::class, 'approve'])->middleware('can:comisiones.approve')->name('aprobar');
    Route::post('cerrar', [SettlementActionController::class, 'close'])->middleware('can:comisiones.close')->name('cerrar');
    Route::post('complementaria', [SettlementActionController::class, 'complement'])->middleware('can:comisiones.calculate')->name('complementaria');
});
