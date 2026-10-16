<?php

// Asesores web routes. AsesoresServiceProvider adds the "web" middleware,
// the /asesores URL prefix and the "asesores." route-name prefix:
// Route::get('/', ...)->name('inicio')
// becomes GET /asesores named asesores.inicio.
//
// Everything needs a signed-in user with asesores.view (advisors and sales
// managers). What each of them sees is decided by Core's portfolio scope
// behind CarteraRepository, never by a parameter of the URL.

use App\Modules\Asesores\Http\Controllers\ClientController;
use App\Modules\Asesores\Http\Controllers\DashboardController;
use App\Modules\Asesores\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:asesores.view'])->group(function () {
    Route::get('/', DashboardController::class)->name('inicio');

    Route::get('clientes', [ClientController::class, 'index'])->name('clientes.index');

    Route::get('historico', HistoryController::class)->name('historico');
});
