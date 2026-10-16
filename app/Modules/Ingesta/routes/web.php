<?php

use App\Modules\Ingesta\Http\Controllers\LoadHistoryController;
use App\Modules\Ingesta\Http\Controllers\ManualUploadController;
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

// Manual upload of the cartera Excel, the fallback when the robot fails:
// form, preview, then confirm or discard.
Route::middleware(['auth', 'can:ingesta.upload'])->prefix('carga-manual')->name('manual.')->group(function () {
    Route::get('/', [ManualUploadController::class, 'create'])->name('create');
    Route::post('/', [ManualUploadController::class, 'preview'])->name('preview');
    Route::post('{token}/confirmar', [ManualUploadController::class, 'confirm'])->whereUuid('token')->name('confirm');
    Route::post('{token}/descartar', [ManualUploadController::class, 'discard'])->whereUuid('token')->name('discard');
});
