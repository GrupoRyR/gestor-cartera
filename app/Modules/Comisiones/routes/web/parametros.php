<?php

use App\Modules\Comisiones\Http\Controllers\ParametersController;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Support\Facades\Route;

// «Parámetros» (§7.2): the only screen where the rules change. One tab per
// kind (?fecha=YYYY-MM-DD shows another day), the history of each key and
// the forms that add a version. {tipo} is a ParameterKind value: codigo,
// tramos, equipo, derivada or opciones. Everything needs comisiones.parameters.

Route::middleware(['auth', 'can:comisiones.parameters'])->prefix('parametros')->name('parametros.')->group(function () {
    Route::get('{pestana?}', [ParametersController::class, 'index'])->whereIn('pestana', ParameterKind::tabs())->name('index');
    Route::get('{tipo}/{clave}', [ParametersController::class, 'history'])
        ->whereIn('tipo', ParameterKind::values())->where('clave', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('historial');
});
