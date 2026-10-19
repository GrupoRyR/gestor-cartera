<?php

use App\Modules\Cobro\Http\Controllers\ExclusionController;
use Illuminate\Support\Facades\Route;

// Cobro web routes. CobroServiceProvider adds the "web" middleware,
// the /cobro URL prefix and the "cobro." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /cobro named cobro.index.

// Exclusion list: clients that receive no collection mail.
Route::middleware(['auth', 'can:cobro.manage'])->prefix('exclusiones')->name('exclusiones.')->group(function () {
    Route::get('/', [ExclusionController::class, 'index'])->name('index');
    Route::post('/', [ExclusionController::class, 'store'])->name('store');
    Route::post('quitar', [ExclusionController::class, 'destroy'])->name('destroy');
});
