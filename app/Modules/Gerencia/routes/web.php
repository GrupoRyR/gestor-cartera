<?php

// Gerencia web routes. GerenciaServiceProvider adds the "web" middleware,
// the /gerencia URL prefix and the "gerencia." route-name prefix:
// Route::get('/', ...)->name('tablero') is GET /gerencia named gerencia.tablero.

use App\Modules\Gerencia\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

// The portfolio manager only (gerencia.view).
Route::middleware(['auth', 'can:gerencia.view'])->group(function () {
    Route::get('/', BoardController::class)->name('tablero');
});
