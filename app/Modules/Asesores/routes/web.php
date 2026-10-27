<?php

// Asesores web routes. AsesoresServiceProvider adds the "web" middleware,
// the /asesores URL prefix and the "asesores." route-name prefix:
// Route::get('/', ...)->name('inicio')
// becomes GET /asesores named asesores.inicio.
//
// Everything needs a signed-in user with asesores.view (advisors and sales
// managers). What each of them sees is decided by Core's portfolio scope
// behind CarteraRepository, never by a parameter of the URL.

use App\Modules\Asesores\Http\Controllers\AgendaController;
use App\Modules\Asesores\Http\Controllers\ClientController;
use App\Modules\Asesores\Http\Controllers\CommitmentController;
use App\Modules\Asesores\Http\Controllers\DashboardController;
use App\Modules\Asesores\Http\Controllers\ExportController;
use App\Modules\Asesores\Http\Controllers\HistoryController;
use App\Modules\Asesores\Http\Controllers\NoteController;
use App\Modules\Asesores\Http\Controllers\StatementController;
use App\Modules\Asesores\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:asesores.view'])->group(function () {
    Route::get('/', DashboardController::class)->name('inicio');

    // The downloads (both Excel files and the PDF) take 10 a minute per user:
    // asesores-exports, in AsesoresServiceProvider.
    Route::get('clientes', [ClientController::class, 'index'])->name('clientes.index');
    Route::get('clientes/exportar', [ExportController::class, 'portfolio'])->middleware('throttle:asesores-exports')->name('clientes.exportar');
    Route::get('clientes/{client}', [ClientController::class, 'show'])->whereNumber('client')->name('clientes.show');
    Route::get('clientes/{client}/exportar', [ExportController::class, 'client'])->whereNumber('client')->middleware('throttle:asesores-exports')->name('clientes.exportar_cliente');
    Route::get('clientes/{client}/estado-de-cuenta', StatementController::class)->whereNumber('client')->middleware('throttle:asesores-exports')->name('clientes.estado_cuenta');

    Route::get('agenda', AgendaController::class)->name('agenda');

    Route::get('historico', HistoryController::class)->name('historico');

    // Sales managers only: the advisors of their team.
    Route::get('equipo', TeamController::class)->middleware('can:asesores.team')->name('equipo.index');

    // Anyone who views can read a commitment; only advisors write them.
    Route::get('compromisos/{commitment}', [CommitmentController::class, 'show'])->whereNumber('commitment')->name('compromisos.show');

    Route::middleware('can:asesores.manage')->group(function () {
        Route::post('clientes/{client}/compromisos', [CommitmentController::class, 'store'])->whereNumber('client')->name('compromisos.store');
        Route::put('compromisos/{commitment}', [CommitmentController::class, 'update'])->whereNumber('commitment')->name('compromisos.update');
        Route::post('compromisos/{commitment}/cumplir', [CommitmentController::class, 'fulfill'])->whereNumber('commitment')->name('compromisos.fulfill');
        Route::post('compromisos/{commitment}/anular', [CommitmentController::class, 'cancel'])->whereNumber('commitment')->name('compromisos.cancel');

        Route::post('clientes/{client}/notas', [NoteController::class, 'store'])->whereNumber('client')->name('notas.store');
    });
});
