<?php

// Core web routes. CoreServiceProvider adds the "web" middleware,
// no URL prefix (Core serves the site root) and the "core." route-name prefix:
// Route::get('equipo', ...)->name('equipo')
// becomes GET /equipo named core.equipo.

use App\Modules\Core\Http\Controllers\SwitchCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('empresa', SwitchCompanyController::class)->name('empresa.cambiar');
});
