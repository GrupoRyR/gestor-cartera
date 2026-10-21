<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\AugustCollections;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The controls of a period (§7.8, §9): each control with its status
| (cumple, no cumple, no aplica) and the figures behind it.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();
});

/**
 * The status chip of one control: cumple, no_cumple or no_aplica.
 */
function controlStatus(TestResponse $response, string $control): ?string
{
    preg_match('/data-control="'.preg_quote($control, '/').'"[^>]*data-status="([a-z_]+)"/', (string) $response->getContent(), $matches);

    return $matches[1] ?? null;
}

it('serves the controls under the period', function () {
    expect(route('comisiones.periodos.controles', '2026-08', absolute: false))->toBe('/comisiones/periodos/2026-08/controles');
});

it('forbids the controls to roles without comisiones.view', function (array $roles) {
    $this->actingAs(ComisionesUsers::create($roles))->get('/comisiones/periodos/2026-08/controles')->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('asks to calculate a period that has no settlement', function () {
    $this->actingAs(ComisionesUsers::viewer())->get('/comisiones/periodos/2026-08/controles')->assertOk()
        ->assertSeeText('Calcula la liquidación del período para ver sus controles.')
        ->assertDontSee('data-control=', false);
});

it('shows every control passing for a period that reconciles', function () {
    AugustCollections::stage($this->sap);
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-08/informes/generar');

    $response = $this->actingAs(ComisionesUsers::viewer())->get('/comisiones/periodos/2026-08/controles')->assertOk();

    expect(controlStatus($response, '9.1'))->toBe('cumple')
        ->and(controlStatus($response, '9.2'))->toBe('cumple')
        ->and(controlStatus($response, '9.3'))->toBe('cumple')
        ->and(controlStatus($response, '9.4'))->toBe('no_aplica')
        ->and(controlStatus($response, '9.5'))->toBe('cumple')
        ->and(controlStatus($response, '9.6'))->toBe('cumple')
        ->and(controlStatus($response, '9.7'))->toBe('cumple');

    $response
        ->assertSeeText('Ventas cargadas contra ventas por código')
        ->assertSeeText(Money::format('1000000'))
        // Payments 18,210,444 and the crossing 1,000,000.
        ->assertSeeText(Money::format('19210444'))
        // PDFs: 94,585 + 83,231 + 55,487.
        ->assertSeeText(Money::format('233303'))
        ->assertSeeText('3 de 3 informes guardados')
        ->assertSeeText('Recibimos los datos de SAP por el robot de Ingesta, no por archivos CSV con .ctl')
        ->assertSee('data-load="recaudos"', false)
        ->assertSee('data-staged="recaudos"', false)
        ->assertSeeText('Recaudos sin fecha de factura')
        ->assertSee('href="'.route('comisiones.periodos.show', '2026-08').'"', false);

    expect(preg_match('/\p{Lu}{4,}/u', strip_tags($response->getContent())))->toBe(0);
});

it('flags the controls a period does not meet', function () {
    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(['doc_entry' => 5900, 'numero_documento' => 8900, 'slp_code' => 50, 'vendedor' => 'CODIGO 50']),
        // Code 99 has no parameters: its sale is loaded but not settled.
        SapBatches::sale(['doc_entry' => 5901, 'numero_documento' => 8901, 'slp_code' => 99, 'vendedor' => 'VENDEDOR NUEVO', 'total' => '100000.00']),
    ]);
    $this->sap->send(Dataset::Collections, [
        SapBatches::payment(['slp_code' => 99, 'vendedor' => 'VENDEDOR NUEVO', 'valor_pagado_sin_iva' => '250000.00']),
        // No invoice date and no staged invoice: it counts from its own date.
        SapBatches::payment(['doc_entry_pago' => 7002, 'slp_code' => 14, 'fecha_factura' => null, 'doc_entry_factura' => 7777]),
    ]);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $response = $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/controles')->assertOk();

    expect(controlStatus($response, '9.1'))->toBe('no_cumple')
        ->and(controlStatus($response, '9.2'))->toBe('no_cumple')
        ->and(controlStatus($response, '9.6'))->toBe('no_cumple')
        ->and(controlStatus($response, '9.7'))->toBe('no_cumple');

    $response->assertSeeText('Borrador')
        ->assertSeeText('Código de vendedor desconocido')
        ->assertSeeText(Money::format('100000'))
        ->assertSeeText(Money::format('250000'))
        ->assertSee('data-without-invoice-date="1"', false)
        ->assertSeeText('Sin justificar');
});

it('shows an older version when asked', function () {
    AugustCollections::stage($this->sap);
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-08/complementaria', ['motivo' => 'Corrección de un pago mal aplicado']);

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-08/controles?version=1')->assertOk()
        ->assertSeeText('Cerrada');
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-08/controles?version=7')->assertNotFound();
});
