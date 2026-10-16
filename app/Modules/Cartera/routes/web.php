<?php

// Cartera web routes. CarteraServiceProvider adds the "web" middleware,
// the /cartera URL prefix and the "cartera." route-name prefix:
// Route::get('/', ...)->name('tablero') is GET /cartera named cartera.tablero.

use App\Modules\Cartera\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Portfolio manager and analyst (cartera.view); writing and exporting add
// their own permission on the routes that need it.
Route::middleware(['auth', 'can:cartera.view'])->group(function () {
    Route::get('/', DashboardController::class)->name('tablero');
});
