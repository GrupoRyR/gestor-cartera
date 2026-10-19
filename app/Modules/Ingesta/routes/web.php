<?php

use App\Modules\Ingesta\Http\Controllers\LoadHistoryController;
use Illuminate\Support\Facades\Route;

// Ingesta web routes. IngestaServiceProvider adds the "web" middleware,
// the /ingesta URL prefix and the "ingesta." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /ingesta named ingesta.index.

// Load history: every robot batch and manual upload, accepted or rejected.
Route::middleware(['auth', 'can:ingesta.view'])->group(function () {
    Route::get('/', [LoadHistoryController::class, 'index'])->name('index');
    Route::get('cargas/{load}', [LoadHistoryController::class, 'show'])->whereNumber('load')->name('cargas.show');
});
