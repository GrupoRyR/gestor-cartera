<?php

use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Http\Controllers\ExclusionController;
use App\Modules\Cobro\Http\Controllers\ReplyInboxController;
use App\Modules\Cobro\Http\Controllers\RunPreviewController;
use App\Modules\Cobro\Http\Controllers\RunReportController;
use App\Modules\Cobro\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// Cobro web routes. CobroServiceProvider adds the "web" middleware,
// the /cobro URL prefix and the "cobro." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /cobro named cobro.index.

// Preview of the next run, one client's mail and the test send, for each
// collection mail: /cobro/semanal (cobro.semanal.*). The "tipo" default is
// the RunKind value and reaches the controller as its last argument.
foreach ([RunKind::Weekly] as $kind) {
    Route::middleware('auth')->prefix($kind->segment())->name($kind->segment().'.')->group(function () use ($kind) {
        Route::middleware('can:cobro.view')->group(function () use ($kind) {
            Route::get('/', [RunPreviewController::class, 'show'])->defaults('tipo', $kind->value)->name('show');
            Route::get('{empresa}/correo/{cliente}', [RunPreviewController::class, 'mail'])
                ->whereAlpha('empresa')->whereNumber('cliente')->defaults('tipo', $kind->value)->name('correo');
        });

        Route::post('{empresa}/prueba', [RunPreviewController::class, 'test'])
            ->whereAlpha('empresa')->middleware(['can:cobro.manage', 'throttle:10,1'])->defaults('tipo', $kind->value)->name('prueba');
    });
}

// Client replies inbox: replies and bounces, marked read or pending.
Route::middleware(['auth', 'can:cobro.replies'])->prefix('respuestas')->name('respuestas.')->group(function () {
    Route::get('/', [ReplyInboxController::class, 'index'])->name('index');
    Route::post('marcar', [ReplyInboxController::class, 'mark'])->name('mark');
    Route::get('{message}', [ReplyInboxController::class, 'show'])->whereNumber('message')->name('show');
});

// Delivery report: every run and its clients, with bounces.
Route::middleware(['auth', 'can:cobro.view'])->prefix('lotes')->name('lotes.')->group(function () {
    Route::get('/', [RunReportController::class, 'index'])->name('index');
    Route::get('{run}', [RunReportController::class, 'show'])->whereNumber('run')->name('show');
});

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
