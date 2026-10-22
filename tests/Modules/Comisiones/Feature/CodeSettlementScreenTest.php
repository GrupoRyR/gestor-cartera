<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The settlement of one code (§7.5): the rule applied, its sales and
| collection lines (crossings marked "(cruce)"), its captures, and filters
| that narrow the lines while the code totals stay those of the settlement.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();

    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['doc_entry' => 5002, 'numero_documento' => 8002, 'codigo_cliente' => 'C800999111', 'cliente' => 'Constructora Andina SAS', 'fecha_documento' => '2026-07-20', 'fecha_contabilizacion' => '2026-07-20', 'total' => '9915134.00']),
    ]);
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()]);
    $this->sap->send(Dataset::Collections, [
        // 20 days after the invoice: full rate.
        SapBatches::payment(['fecha_pago' => '2026-07-20', 'valor_pagado_sin_iva' => '420168.07']),
        // 70 days after the invoice: 90 % of the rate.
        SapBatches::payment(['doc_entry_pago' => 7002, 'numero_factura' => 8100, 'fecha_factura' => '2026-05-20', 'fecha_pago' => '2026-07-29', 'codigo_cliente' => 'C800999111', 'nombre_cliente' => 'Constructora Andina SAS', 'valor_pagado_sin_iva' => '1000000.00']),
    ]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing()]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'descripcion' => 'Escudos de bodega', 'valor_vendido' => '2000000.00', 'tasa' => '2', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'descuento', 'valor' => '50000.00', 'descripcion' => 'Anticipo de junio', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
});

it('serves the settlement of a code under its period', function () {
    expect(route('comisiones.periodos.codigo', ['periodo' => '2026-07', 'codigo' => 14], absolute: false))->toBe('/comisiones/periodos/2026-07/codigos/14');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/999')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-08/codigos/14')->assertNotFound();
});

it('forbids the settlement of a code to roles without comisiones.view', function (array $roles) {
    $this->actingAs(ComisionesUsers::create($roles))->get('/comisiones/periodos/2026-07/codigos/14')->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('shows the rule, the figures, the lines and the captures of a code', function () {
    $response = $this->actingAs(ComisionesUsers::viewer())->get('/comisiones/periodos/2026-07/codigos/14')->assertOk();

    $response->assertSeeText('Código 14 · Mónica Rivera')
        // Rule: rates, minimum, scope and tranches.
        ->assertSeeText('0,75 %')
        ->assertSeeText('90 %')
        ->assertSeeText('Regular')
        ->assertSeeText('66 a 80 días')
        // Figures.
        ->assertSeeText(Money::format('69915134'))
        ->assertSeeText(Money::format('77241857'))
        ->assertSeeText('90,51 %')
        ->assertSeeText(Money::format('524364'))
        // Sales lines.
        ->assertSeeText('Constructora Andina SAS')
        // Collection lines: invoice, client, dates, days, share, base; the crossing is marked.
        ->assertSeeText('Ferretería El Tornillo SAS (cruce)')
        ->assertSeeText('30/06/2026')
        ->assertSeeText('29/07/2026')
        ->assertSeeText(Money::format('1000000'))
        ->assertSeeText('3 transacciones')
        // Captures.
        ->assertSeeText('Escudos de bodega')
        ->assertSeeText('Anticipo de junio');

    expect(substr_count($response->getContent(), 'data-line="pago"'))->toBe(2)
        ->and(substr_count($response->getContent(), 'data-line="cruce"'))->toBe(1)
        ->and(substr_count($response->getContent(), 'data-line="venta"'))->toBe(2);
});

it('filters the lines by client, invoice and dates, keeping the code totals', function () {
    $filtered = $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14?cliente=andina')->assertOk();

    expect(substr_count($filtered->getContent(), 'data-line="pago"'))->toBe(1)
        ->and(substr_count($filtered->getContent(), 'data-line="cruce"'))->toBe(0)
        ->and(substr_count($filtered->getContent(), 'data-line="venta"'))->toBe(1);
    $filtered->assertSeeText(Money::format('524364'))->assertSeeText('Muestras 1 de 3 líneas de recaudo');

    $byInvoice = $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14?factura=8100')->assertOk();
    expect(substr_count($byInvoice->getContent(), 'data-line="pago"'))->toBe(1);

    $byDates = $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14?desde=2026-07-21&hasta=2026-07-31')->assertOk();
    expect(substr_count($byDates->getContent(), 'data-line="pago"'))->toBe(1)
        ->and(substr_count($byDates->getContent(), 'data-line="cruce"'))->toBe(1)
        ->and(substr_count($byDates->getContent(), 'data-line="venta"'))->toBe(0);

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/codigos/14?desde=julio')->assertSessionHasErrors('desde');
});

it('answers 404 for the code of a period never calculated', function () {
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-06/codigos/14')->assertNotFound();
});
