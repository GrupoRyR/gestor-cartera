<?php

use App\Modules\Comisiones\Http\Controllers\ParameterFormController;
use App\Modules\Comisiones\Http\Controllers\ParametersController;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Support\Facades\Route;

// «Parámetros» (§7.2): the only screen where the rules change. One tab per
// kind (?fecha=YYYY-MM-DD shows another day), the history of each key and
// the forms that add a version. {tipo} is a ParameterKind value: codigo,
// tramos, equipo, derivada or opciones. Everything needs comisiones.parameters.

Route::middleware(['auth', 'can:comisiones.parameters'])->prefix('parametros')->name('parametros.')->group(function () {
    Route::get('{pestana?}', [ParametersController::class, 'index'])->whereIn('pestana', ParameterKind::tabs())->name('index');

    // Before the history, so "nueva" is never read as a key.
    Route::get('{tipo}/nueva', [ParameterFormController::class, 'create'])->whereIn('tipo', ParameterKind::values())->name('create');
    Route::get('{tipo}/{clave}', [ParametersController::class, 'history'])
        ->whereIn('tipo', ParameterKind::values())->where('clave', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('historial');

    Route::post('codigo', [ParameterFormController::class, 'code'])->name('codigo.store');
    Route::post('tramos', [ParameterFormController::class, 'tranches'])->name('tramos.store');
    Route::post('equipo', [ParameterFormController::class, 'team'])->name('equipo.store');
    Route::post('derivada', [ParameterFormController::class, 'derived'])->name('derivada.store');
    Route::post('opciones', [ParameterFormController::class, 'options'])->name('opciones.store');
});
