<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Tests\Modules\Asesores\Support\AdvisorWorld;

/*
| The two Excel files and the account statement take at most 10 requests a
| minute from one user (audit S-10), counted together and per user, so one
| advisor at the limit never blocks another.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
});

it('limits every download of the module', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:asesores-exports');
})->with([
    'asesores.clientes.exportar',
    'asesores.clientes.exportar_cliente',
    'asesores.clientes.estado_cuenta',
]);

it('answers 429 to the 11th download of a minute, and only to that user', function () {
    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar'))->assertOk();
    }

    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar'))->assertTooManyRequests();
    $this->actingAs($this->world->bruno)->get(route('asesores.clientes.exportar'))->assertOk();
});
