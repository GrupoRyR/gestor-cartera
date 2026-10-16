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
use App\Modules\Core\Http\Controllers\Portfolio\ActivityController;
use App\Modules\Core\Http\Controllers\SwitchCompanyController;
use App\Modules\Core\Http\Controllers\Team\TeamMemberController;
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

// Equipo: admins only. {user} finds only users who are not deleted, except
// on restore.
Route::middleware(['auth', 'can:core.team.manage'])
    ->prefix('equipo')
    ->name('equipo.')
    ->controller(TeamMemberController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('nuevo', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{user}/editar', 'edit')->whereNumber('user')->name('edit');
        Route::put('{user}', 'update')->whereNumber('user')->name('update');
        Route::delete('{user}', 'destroy')->whereNumber('user')->name('destroy');
        Route::post('{user}/restaurar', 'restore')->whereNumber('user')->withTrashed()->name('restore');
    });

// Client log (gestiones): portfolio managers and analysts remove an entry of
// a client within their reach. {activity} finds only entries not removed.
Route::middleware(['auth', 'can:core.activities.delete'])
    ->delete('gestiones/{activity}', [ActivityController::class, 'destroy'])
    ->whereNumber('activity')
    ->name('gestiones.destroy');
