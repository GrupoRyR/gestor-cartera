<?php

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
| belong to no module go here.
|
*/

// Only in local: component gallery for phase 0b (Módulo 0D). Any other
// environment answers 404, as if the page did not exist.
Route::get('/dev/componentes', function (): View {
    abort_unless(app()->isLocal(), 404);

    return view('design.gallery');
})->name('dev.componentes');
