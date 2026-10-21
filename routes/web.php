<?php

use App\Http\Controllers\Dev\DemoLoginController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Base web routes
|--------------------------------------------------------------------------
|
| Business routes live in each module (app/Modules/<Modulo>/routes/web.php),
| loaded by its service provider with the module prefix and name. Core owns
| "/" (home by role) and the sign-in routes. Only cross-cutting routes that
| belong to no module go here: the local development tools under /dev.
|
*/

// Only in local: component gallery for phase 0b (Módulo 0D). Any other
// environment answers 404, as if the page did not exist.
Route::get('/dev/componentes', function (): View {
    abort_unless(app()->isLocal(), 404);

    return view('design.gallery');
})->name('dev.componentes');

// Only in local: sign in as a demo user without Microsoft, to walk every
// screen with the data of the demo seeders. The routes do not even exist in
// another environment; the controller also checks the environment and that
// the request comes from this machine, and the sign-in is a POST with CSRF.
if (app()->isLocal()) {
    Route::get('/dev/entrar', [DemoLoginController::class, 'index'])->name('dev.entrar');
    Route::post('/dev/entrar/{user}', [DemoLoginController::class, 'store'])
        ->whereNumber('user')
        ->name('dev.entrar.usuario');
}
