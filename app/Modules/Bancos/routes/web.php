<?php

use App\Modules\Bancos\Http\Controllers\LoadController;
use App\Modules\Bancos\Http\Controllers\MovementController;
use App\Modules\Core\Contracts\Enums\Bank;
use Illuminate\Support\Facades\Route;

// Bancos web routes. BancosServiceProvider adds the "web" middleware,
// the /bancos URL prefix and the "bancos." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /bancos named bancos.index.

// Everything below needs a signed-in user who may see the bank movements.
Route::middleware(['auth', 'can:bancos.view'])->group(function () {
    // Plain redirects (no closures) so the routes can be cached.
    Route::redirect('/', '/bancos/movimientos')->name('index');

    // Credits of each bank, one tab per bank.
    Route::redirect('movimientos', '/bancos/movimientos/'.Bank::cases()[0]->value)->name('movimientos.index');
    Route::get('movimientos/{bank}', [MovementController::class, 'index'])
        ->whereIn('bank', array_column(Bank::cases(), 'value'))
        ->name('movimientos.banco');

    // One credit and where it stands with the analyst.
    Route::get('movimiento/{movement}', [MovementController::class, 'show'])->whereNumber('movement')->name('movimientos.show');

    // Statements loaded so far, from the folder, the app and Treasury's API.
    Route::get('cargas', [LoadController::class, 'index'])->name('cargas.index');
    Route::get('cargas/{load}', [LoadController::class, 'show'])->whereNumber('load')->name('cargas.show');
});

// Registering the cash receipt number is the analyst's job.
Route::patch('movimiento/{movement}', [MovementController::class, 'update'])
    ->whereNumber('movement')
    ->middleware(['auth', 'can:bancos.apply'])
    ->name('movimientos.update');

// Upload of a statement by hand.
Route::post('cargas', [LoadController::class, 'store'])->middleware(['auth', 'can:bancos.import'])->name('cargas.store');
