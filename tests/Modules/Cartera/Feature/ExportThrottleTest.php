<?php

use Illuminate\Support\Facades\Route;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cartera\Support\CarteraUsers;

/*
| Every download of the module takes at most 10 requests a minute from one
| user (audit S-10), counted together across the exports and per user, so
| one user at the limit never blocks another.
*/

beforeEach(function () {
    ContractFakes::install($this->app);
});

it('limits every download of the module', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:cartera-exports');
})->with([
    'cartera.clientes.exportar',
    'cartera.clientes.exportar-ficha',
]);

it('answers 429 to the 11th download of a minute, and only to that user', function () {
    $analyst = CarteraUsers::analyst();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($analyst)->get('/cartera/clientes/exportar')->assertOk();
    }

    $this->actingAs($analyst)->get('/cartera/clientes/exportar')->assertTooManyRequests();
    $this->actingAs(CarteraUsers::manager())->get('/cartera/clientes/exportar')->assertOk();
});
