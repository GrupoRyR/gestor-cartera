<?php

use App\Modules\Comisiones\Http\Controllers\ControlsController;
use App\Modules\Comisiones\Http\Controllers\ReportsController;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use Illuminate\Support\Facades\Route;

// The controls and the reports of a period (§7.7, §7.8, §8.2, §9): the
// period tabs «Controles» and «Informes», both read from the stored
// settlement. {periodo} is a month, YYYY-MM (PeriodRoute::PATTERN);
// ?version=N picks an older version. Only a closed version produces reports.

Route::middleware(['auth', 'can:comisiones.view'])
    ->prefix('periodos/{periodo}')
    ->where(['periodo' => PeriodRoute::PATTERN])
    ->name('periodos.')
    ->group(function () {
        Route::get('controles', ControlsController::class)->name('controles');
    });

Route::middleware(['auth', 'can:comisiones.reports'])
    ->prefix('periodos/{periodo}')
    ->where(['periodo' => PeriodRoute::PATTERN])
    ->name('periodos.informes.')
    ->group(function () {
        Route::get('informes', [ReportsController::class, 'index'])->name('index');
        Route::post('informes/generar', [ReportsController::class, 'generate'])->name('generar');
        // The downloads take 10 a minute per user (comisiones-exports).
        Route::get('informes.zip', [ReportsController::class, 'zip'])->middleware('throttle:comisiones-exports')->name('zip');
        Route::get('informes/nomina.xlsx', [ReportsController::class, 'payroll'])->middleware('throttle:comisiones-exports')->name('nomina');
        // A code ("14") or a derived commission ("85.1").
        Route::get('informes/{referencia}.pdf', [ReportsController::class, 'pdf'])->where('referencia', '\d+(\.\d+)?')->middleware('throttle:comisiones-exports')->name('pdf');
    });
