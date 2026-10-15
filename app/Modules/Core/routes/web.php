<?php

// Core web routes. CoreServiceProvider adds the "web" middleware,
// no URL prefix (Core serves the site root) and the "core." route-name prefix:
// Route::get('equipo', ...)->name('equipo')
// becomes GET /equipo named core.equipo.

use App\Modules\Core\Http\Controllers\Auth\LoginController;
use App\Modules\Core\Http\Controllers\Auth\LogoutController;
use App\Modules\Core\Http\Controllers\Auth\MicrosoftCallbackController;
use App\Modules\Core\Http\Controllers\Auth\MicrosoftRedirectController;
use App\Modules\Core\Http\Controllers\HomeController;
use App\Modules\Core\Http\Controllers\NoAccessController;
use App\Modules\Core\Http\Controllers\SwitchCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', LoginController::class)->name('login');
    Route::get('auth/microsoft', MicrosoftRedirectController::class)->name('auth.redirect');
    // Must match MICROSOFT_REDIRECT_URI.
    Route::get('auth/microsoft/callback', MicrosoftCallbackController::class)->name('auth.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('inicio');
    Route::get('sin-acceso', NoAccessController::class)->name('sin-acceso');
    Route::post('empresa', SwitchCompanyController::class)->name('empresa.cambiar');
    Route::post('logout', LogoutController::class)->name('logout');
});
