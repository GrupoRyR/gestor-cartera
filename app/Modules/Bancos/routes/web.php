<?php

use App\Modules\Bancos\Http\Controllers\LoadController;
use Illuminate\Support\Facades\Route;

// Bancos web routes. BancosServiceProvider adds the "web" middleware,
// the /bancos URL prefix and the "bancos." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /bancos named bancos.index.

// Everything below needs a signed-in user who may see the bank movements.
Route::middleware(['auth', 'can:bancos.view'])->group(function () {
    // Statements loaded so far, from the folder, the app and Treasury's API.
    Route::get('cargas', [LoadController::class, 'index'])->name('cargas.index');
    Route::get('cargas/{load}', [LoadController::class, 'show'])->whereNumber('load')->name('cargas.show');
});

// Upload of a statement by hand.
Route::post('cargas', [LoadController::class, 'store'])->middleware(['auth', 'can:bancos.import'])->name('cargas.store');
