<?php

use App\Modules\Cobro\Http\Controllers\ExclusionController;
use App\Modules\Cobro\Http\Controllers\TemplateController;
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

// Weekly collection template of each company, and its preview with sample data.
Route::middleware('auth')->prefix('plantillas')->name('plantillas.')->group(function () {
    Route::middleware('can:cobro.manage')->group(function () {
        Route::get('/', [TemplateController::class, 'edit'])->name('edit');
        Route::put('{empresa}', [TemplateController::class, 'update'])->whereAlpha('empresa')->name('update');
        Route::post('{empresa}/restaurar', [TemplateController::class, 'reset'])->whereAlpha('empresa')->name('reset');
    });

    Route::get('{empresa}/vista-previa', [TemplateController::class, 'preview'])->whereAlpha('empresa')->middleware('can:cobro.view')->name('preview');
});
