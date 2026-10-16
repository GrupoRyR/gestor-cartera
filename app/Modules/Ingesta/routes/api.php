<?php

use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Http\Controllers\ReceiveBatchController;
use App\Modules\Ingesta\Http\Middleware\VerifyRobotSignature;
use Illuminate\Support\Facades\Route;

// Ingesta API routes. IngestaServiceProvider adds the "api" middleware,
// the /api/ingesta URL prefix and the "ingesta.api." route-name prefix:
// Route::get('estado', ...)->name('estado')
// becomes GET /api/ingesta/estado named ingesta.api.estado.

// The ETL robot publishes each batch here (wire protocol in the module README).
Route::post('{lote}', ReceiveBatchController::class)
    ->whereIn('lote', array_column(Dataset::cases(), 'value'))
    ->middleware(['throttle:ingesta-robot', VerifyRobotSignature::class])
    ->name('lotes.store');
