<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SettlementRows;

/*
| The version a screen of a period shows (?version= or the latest): a
| stored result that fails its check is reported and sends the user to the
| period screen, which says so; a period never calculated answers 404 on
| the screens that need a settlement.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    ContractFakes::install($this->app);
    $this->analyst = ComisionesUsers::analyst();
    $this->july = Period::of(2026, 7);
    $this->settlements = app(EloquentSettlements::class);
});

it('reports a corrupt version and gives no selection', function () {
    Exceptions::fake();
    SettlementRows::corrupt('2026-07');

    expect(SelectedSettlement::readable(Request::create('/'), $this->july, $this->settlements))->toBeNull()
        ->and(SelectedSettlement::calculated(Request::create('/'), $this->july, $this->settlements))->toBeNull();

    Exceptions::assertReported(CorruptSettlement::class);
});

it('selects nothing for a period never calculated, and refuses it where a settlement is needed', function () {
    $selected = SelectedSettlement::readable(Request::create('/'), $this->july, $this->settlements);

    expect($selected?->settlement)->toBeNull()
        ->and($selected?->latestVersion)->toBeNull()
        ->and(fn () => SelectedSettlement::calculated(Request::create('/'), $this->july, $this->settlements))->toThrow(NotFoundHttpException::class);
});

it('says a version is corrupt on the period screen and sends the other screens there', function () {
    Exceptions::fake();
    SettlementRows::corrupt('2026-07');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('La liquidación guardada no pasó la verificación de integridad');
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14')->assertRedirect('/comisiones/periodos/2026-07');
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado')->assertRedirect('/comisiones/periodos/2026-07');
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado.xlsx')->assertRedirect('/comisiones/periodos/2026-07');
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado.pdf')->assertRedirect('/comisiones/periodos/2026-07');

    Exceptions::assertReportedCount(5);
});

it('answers 404 for the screens of a period never calculated', function () {
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado.xlsx')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado.pdf')->assertNotFound();
});
