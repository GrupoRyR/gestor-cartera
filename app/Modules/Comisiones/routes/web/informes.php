<?php

use App\Modules\Comisiones\Http\Controllers\ReportsController;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use Illuminate\Support\Facades\Route;

// The reports of a period (§7.7, §8.2): the period tab «Informes».
// {periodo} is a month, YYYY-MM (PeriodRoute::PATTERN); ?version=N picks
// an older version. Only a closed version produces reports.

Route::middleware(['auth', 'can:comisiones.reports'])
    ->prefix('periodos/{periodo}')
    ->where(['periodo' => PeriodRoute::PATTERN])
    ->name('periodos.informes.')
    ->group(function () {
        Route::get('informes', [ReportsController::class, 'index'])->name('index');
        Route::post('informes/generar', [ReportsController::class, 'generate'])->name('generar');
        Route::get('informes.zip', [ReportsController::class, 'zip'])->name('zip');
        Route::get('informes/nomina.xlsx', [ReportsController::class, 'payroll'])->name('nomina');
        // A code ("14") or a derived commission ("85.1").
        Route::get('informes/{referencia}.pdf', [ReportsController::class, 'pdf'])->where('referencia', '\d+(\.\d+)?')->name('pdf');
    });
