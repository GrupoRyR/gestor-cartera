<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;

/*
| Every file the module hands out (reports, payroll, consolidated, capture
| supports and the budget template) takes at most 10 requests a minute from
| one user (audit S-10), counted together and per user, so one user at the
| limit never blocks another.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    ContractFakes::install($this->app);
});

it('limits every download of the module', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:comisiones-exports');
})->with([
    'comisiones.periodos.informes.zip',
    'comisiones.periodos.informes.nomina',
    'comisiones.periodos.informes.pdf',
    'comisiones.periodos.consolidado.xlsx',
    'comisiones.periodos.consolidado.pdf',
    'comisiones.ajustes.soporte',
    'comisiones.presupuesto.template',
]);

it('leaves the screens and the report generation alone', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->not->toContain('throttle:comisiones-exports');
})->with([
    'comisiones.periodos.informes.index',
    'comisiones.periodos.informes.generar',
    'comisiones.periodos.consolidado',
    'comisiones.presupuesto.index',
]);

it('answers 429 to the 11th download of a minute, and only to that user', function () {
    $analyst = ComisionesUsers::analyst();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($analyst)->get('/comisiones/presupuesto/2026-07/plantilla')->assertOk();
    }

    $this->actingAs($analyst)->get('/comisiones/presupuesto/2026-07/plantilla')->assertTooManyRequests();
    $this->actingAs(ComisionesUsers::analyst('luis.mora@segurex.com', 'Luis Mora'))
        ->get('/comisiones/presupuesto/2026-07/plantilla')->assertOk();
});
