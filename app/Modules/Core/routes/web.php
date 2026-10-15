<?php

// Core web routes. CoreServiceProvider adds the "web" middleware,
// no URL prefix (Core serves the site root) and the "core." route-name prefix:
// Route::get('equipo', ...)->name('equipo')
// becomes GET /equipo named core.equipo.

use App\Modules\Core\Http\Controllers\HomeController;
use App\Modules\Core\Http\Controllers\NoAccessController;
use App\Modules\Core\Http\Controllers\SwitchCompanyController;
use App\Modules\Core\Http\Controllers\Team\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('inicio');
    Route::get('sin-acceso', NoAccessController::class)->name('sin-acceso');
    Route::post('empresa', SwitchCompanyController::class)->name('empresa.cambiar');
});

// Equipo: admins only.
Route::middleware(['auth', 'can:core.team.manage'])
    ->prefix('equipo')
    ->name('equipo.')
    ->controller(TeamMemberController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
    });
