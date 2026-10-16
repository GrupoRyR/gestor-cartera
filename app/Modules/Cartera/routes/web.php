<?php

// Cartera web routes. CarteraServiceProvider adds the "web" middleware,
// the /cartera URL prefix and the "cartera." route-name prefix:
// Route::get('/', ...)->name('tablero') is GET /cartera named cartera.tablero.

use App\Modules\Cartera\Http\Controllers\ClientFileController;
use App\Modules\Cartera\Http\Controllers\ClientListController;
use App\Modules\Cartera\Http\Controllers\DashboardController;
use App\Modules\Cartera\Http\Controllers\KanbanController;
use App\Modules\Cartera\Http\Controllers\MoveClientController;
use App\Modules\Cartera\Http\Controllers\StoreManagementController;
use Illuminate\Support\Facades\Route;

// Portfolio manager and analyst (cartera.view); writing and exporting add
// their own permission on the routes that need it.
Route::middleware(['auth', 'can:cartera.view'])->group(function () {
    Route::get('/', DashboardController::class)->name('tablero');
    Route::get('clientes', ClientListController::class)->name('clientes.index');
    Route::get('clientes/{client}', ClientFileController::class)->whereNumber('client')->name('clientes.show');
    Route::get('kanban', KanbanController::class)->name('kanban');

    // Changing a status is for those with core.clients.change_status (Core's
    // permission); the board page and the client file both post here.
    Route::post('clientes/{client}/estado', MoveClientController::class)
        ->whereNumber('client')
        ->middleware('can:core.clients.change_status')
        ->name('clientes.estado');

    // Calls, notes and discounts offered: cartera.manage.
    Route::post('clientes/{client}/gestiones', StoreManagementController::class)
        ->whereNumber('client')
        ->middleware('can:cartera.manage')
        ->name('gestiones.store');
});
