<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The dashboard of a period (§7.1): the SAP loads, the month to date per
| code from a live preview of the engine against the budget, the alerts,
| the captures waiting for approval and the status of the settlement.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-07-31 18:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
});

/** Code 14 of July 2026 (Annex 2), and a sale of code 99, which has no parameters. */
function dashboardJuly(SapBatches $sap, int $budgetBy): void
{
    $sap->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
        SapBatches::sale(['doc_entry' => 5002, 'numero_documento' => 8002, 'slp_code' => 99, 'vendedor' => 'VENDEDOR NUEVO', 'total' => '100000.00']),
    ]);
    $sap->send(Dataset::Collections, [SapBatches::payment()]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $budgetBy]);
}

it('serves the dashboard at /comisiones', function () {
    expect(route('comisiones.tablero', absolute: false))->toBe('/comisiones');
});

it('sends guests to the login', function () {
    $this->get('/comisiones')->assertRedirect('/login');
});

it('forbids the dashboard to roles without comisiones.view', function (array $roles) {
    $this->actingAs(ComisionesUsers::create($roles))->get('/comisiones')->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('opens on the month in course, with nothing loaded yet', function () {
    $this->actingAs(ComisionesUsers::viewer())->get('/comisiones')->assertOk()
        ->assertSeeText('Tablero de comisiones')
        ->assertSeeText('Comisiones · julio de 2026')
        ->assertSee('value="2026-07"', false)
        ->assertSeeText('Sin calcular')
        ->assertSeeText('Aún no llegan cargas de SAP');
});

it('shows the month to date per code from a live preview against the budget', function () {
    dashboardJuly($this->sap, $this->analyst->id);

    $response = $this->actingAs($this->analyst)->get('/comisiones?periodo=2026-07')->assertOk();

    $response->assertSee('data-code="14"', false)
        ->assertSeeText('Mónica Rivera')
        ->assertSeeText(Money::format('69915134'))
        ->assertSeeText(Money::format('77241857'))
        ->assertSeeText('90,51 %')
        ->assertSeeText(Money::format('524364'))
        ->assertSeeText(Money::format('420168'))
        // The chart, with the minimum marked and said in words.
        ->assertSee('data-part="compliance-bars"', false)
        ->assertSeeText('Cumple el mínimo de 90 %');

    // The preview stores nothing.
    expect(SettlementRecord::query()->count())->toBe(0);
});

it('counts the alerts of the preview and the captures waiting for approval', function () {
    dashboardJuly($this->sap, $this->analyst->id);
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'descripcion' => 'Escudos', 'valor_vendido' => '1.00', 'tasa' => '1', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '1.00', 'descripcion' => 'x', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '1.00', 'descripcion' => 'x', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-06', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '1.00', 'descripcion' => 'x', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);
    CrossingRecord::query()->create([
        'clave' => 'manual-1', 'origen' => 'manual', 'fecha_cruce' => '2026-07-18', 'tipo_cruce' => 'anticipo', 'estado_cruce' => 'vigente',
        'codigo_cliente' => 'C1', 'valor_compensado' => '1.00', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id,
    ]);

    $this->actingAs($this->analyst)->get('/comisiones?periodo=2026-07')->assertOk()
        ->assertSee('data-stat="bloqueantes" data-value="1"', false)
        ->assertSee('data-stat="pendientes" data-value="3"', false)
        ->assertSeeText('1 OBR · 1 reintegros o descuentos · 1 cruces manuales · 0 reasignaciones');
});

it('shows each SAP load with its rows and the loads Ingesta rejected since', function () {
    dashboardJuly($this->sap, $this->analyst->id);
    $this->fakes->ingesta->failNextWith(new InvalidBatch('Column total is missing.', ['total' => ['Missing.']]));

    try {
        $this->sap->send(Dataset::Sales, [SapBatches::sale()]);
    } catch (InvalidBatch) {
        // Ingesta keeps it in its history as rejected.
    }

    $this->actingAs($this->analyst)->get('/comisiones?periodo=2026-07')->assertOk()
        ->assertSee('data-dataset="ventas"', false)
        ->assertSeeText('Ventas')
        ->assertSeeText('Recaudos')
        ->assertSeeText('31/07/2026 18:00')
        ->assertSeeText('1 carga rechazada')
        ->assertSeeText('Sin cargas');
});

it('links to the settlement of the period with its status', function () {
    dashboardJuly($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($this->analyst)->get('/comisiones?periodo=2026-07')->assertOk()
        ->assertSeeText('Borrador')
        ->assertSee('href="'.route('comisiones.periodos.show', '2026-07').'"', false);
});

it('says so when the period has no parameters in force', function () {
    $this->actingAs($this->analyst)->get('/comisiones?periodo=2025-12')->assertOk()
        ->assertSeeText('No hay una tabla de tramos de recaudo vigente para 2025-12');
});

it('ignores a period that is not a month', function () {
    $this->actingAs($this->analyst)->get('/comisiones?periodo=julio')->assertOk()
        ->assertSee('value="2026-07"', false);
});
