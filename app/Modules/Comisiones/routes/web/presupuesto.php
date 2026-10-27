<?php

use App\Modules\Comisiones\Http\Controllers\BudgetController;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use Illuminate\Support\Facades\Route;

// The monthly budget (§7.3): the table of a period (?periodo=YYYY-MM), the
// edit of one code, the template and its upload.

Route::get('presupuesto', [BudgetController::class, 'index'])->middleware(['auth', 'can:comisiones.view'])->name('presupuesto.index');

Route::middleware(['auth', 'can:comisiones.capture'])->prefix('presupuesto/{periodo}')->where(['periodo' => PeriodRoute::PATTERN])->name('presupuesto.')->group(function () {
    Route::put('{codigo}', [BudgetController::class, 'update'])->whereNumber('codigo')->name('update');
    Route::delete('{codigo}', [BudgetController::class, 'destroy'])->whereNumber('codigo')->name('destroy');
    Route::post('carga', [BudgetController::class, 'import'])->name('import');
    // A download: 10 a minute per user (comisiones-exports).
    Route::get('plantilla', [BudgetController::class, 'template'])->middleware('throttle:comisiones-exports')->name('template');
});
