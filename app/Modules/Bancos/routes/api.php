<?php

use App\Modules\Bancos\Http\Controllers\Api\ReceiveMovementsController;
use App\Modules\Bancos\Http\Middleware\VerifyTreasuryToken;
use Illuminate\Support\Facades\Route;

// Bancos API routes. BancosServiceProvider adds the "api" middleware,
// the /api URL prefix (no module segment) and the "bancos.api." route-name prefix:
// Route::get('recaudo', ...)->name('recaudo')
// becomes GET /api/recaudo named bancos.api.recaudo.

// Treasury's app pushes bank movements (contract in ReceiveMovementsRequest).
Route::post('movimientos', ReceiveMovementsController::class)
    ->middleware(['throttle:bancos-api', VerifyTreasuryToken::class.':ingest_token'])
    ->name('movimientos.store');
