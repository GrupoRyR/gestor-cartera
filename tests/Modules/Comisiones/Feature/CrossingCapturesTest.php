<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| Manual crossings (§5.4: the backup of Report 4, natural key manual-{uuid},
| period = month of the crossing date) and reassignments of a crossing to
| another seller, both with support and pending until another user
| approves them.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    Storage::fake('local');
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manualCrossingForm(array $overrides = []): array
{
    return array_replace([
        'periodo' => '2026-07',
        'vendedor' => '15',
        'tipo_cruce' => 'cliente-proveedor',
        'factura' => 'FV-8123',
        'fecha_factura' => '2026-06-10',
        'fecha_cruce' => '2026-07-25',
        'codigo_cliente' => 'C800111222',
        'razon_social' => 'Distribuciones La 80 SAS',
        'tercero' => 'P800111222',
        'importe_original' => '$ 2.713.450',
        'valor_cruzado' => '2.713.450',
        'iva_factura' => '433.240',
        'valor_sin_iva' => '',
        'soporte' => UploadedFile::fake()->create('cruce.pdf', 80, 'application/pdf'),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reassignmentForm(array $overrides = []): array
{
    return array_replace([
        'periodo' => '2026-07',
        'clave_cruce' => 'sap-9001-0',
        'codigo' => '15',
        'motivo' => 'El cliente pasó a la zona de Bogotá en junio',
        'soporte' => UploadedFile::fake()->create('correo.pdf', 40, 'application/pdf'),
    ], $overrides);
}

it('serves the crossing forms under /comisiones/ajustes', function () {
    expect(route('comisiones.ajustes.cruces.store', absolute: false))->toBe('/comisiones/ajustes/cruces')
        ->and(route('comisiones.ajustes.reasignaciones.store', absolute: false))->toBe('/comisiones/ajustes/reasignaciones');

    $this->post('/comisiones/ajustes/cruces')->assertRedirect('/login');

    $viewer = ComisionesUsers::viewer();
    $this->actingAs($viewer)->post('/comisiones/ajustes/cruces', manualCrossingForm())->assertForbidden();
    $this->actingAs($viewer)->post('/comisiones/ajustes/reasignaciones', reassignmentForm())->assertForbidden();
    $this->actingAs(ComisionesUsers::approver())->post('/comisiones/ajustes/cruces', manualCrossingForm())->assertForbidden();
});

it('registers a manual crossing pending, keyed manual-{uuid} and dated by its crossing date', function () {
    $this->actingAs($this->analyst)->get('/comisiones/ajustes/cruces?periodo=2026-07')->assertOk()
        ->assertSee('name="formulario" value="cruces"', false)
        ->assertSeeText('Registrar cruce manual');

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm())
        ->assertRedirect('/comisiones/ajustes/cruces?periodo=2026-07')
        ->assertSessionHas('status', 'Registraste el cruce manual de 2026-07. Queda pendiente hasta que otra persona lo apruebe.');

    $crossing = CrossingRecord::query()->sole();

    expect($crossing->clave)->toMatch('/^manual-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($crossing->origen)->toBe('manual')
        ->and($crossing->estado)->toBe(CaptureStatus::Pending)
        ->and($crossing->estado_cruce)->toBe('vigente')
        ->and($crossing->slp_code_capturado)->toBe(15)
        ->and($crossing->tipo_cruce)->toBe('cliente-proveedor')
        ->and($crossing->numero_factura)->toBe('FV-8123')
        ->and($crossing->fecha_factura->format('Y-m-d'))->toBe('2026-06-10')
        ->and($crossing->fecha_cruce->format('Y-m-d'))->toBe('2026-07-25')
        ->and($crossing->codigo_cliente)->toBe('C800111222')
        ->and($crossing->razon_social)->toBe('Distribuciones La 80 SAS')
        ->and($crossing->codigo_proveedor)->toBe('P800111222')
        ->and($crossing->valor_factura)->toBe('2713450.00')
        ->and($crossing->valor_compensado)->toBe('2713450.00')
        ->and($crossing->iva_factura)->toBe('433240.00')
        ->and($crossing->valor_compensado_sin_iva)->toBeNull()
        ->and($crossing->created_by)->toBe($this->analyst->id);
    Storage::disk('local')->assertExists($crossing->soporte);

    expect(AuditRecord::query()->where('evento', 'comisiones.capture.created')->sole()->sujeto_tipo)->toBe('cruce');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/cruces?periodo=2026-07')->assertOk()
        ->assertSee('data-capture="'.$crossing->id.'" data-estado="pendiente"', false)
        ->assertSeeText('FV-8123')
        ->assertSeeText('Distribuciones La 80 SAS')
        ->assertSeeText('25/07/2026');
});

it('files a manual crossing under the month of its crossing date', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm(['fecha_cruce' => '2026-06-28']))
        ->assertRedirect('/comisiones/ajustes/cruces?periodo=2026-06');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/cruces?periodo=2026-07')->assertOk()->assertDontSee('data-capture=', false);
    $this->actingAs($this->analyst)->get('/comisiones/ajustes/cruces?periodo=2026-06')->assertOk()->assertSee('data-capture=', false);
});

it('asks for the amount without VAT when the invoice VAT is missing', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm(['iva_factura' => '', 'valor_sin_iva' => '']))
        ->assertSessionHasErrors(['valor_sin_iva' => 'Escribe el valor cruzado sin IVA o el IVA de la factura.']);

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm(['iva_factura' => '', 'valor_sin_iva' => '2.280.210']))
        ->assertSessionHasNoErrors();

    expect(CrossingRecord::query()->sole()->valor_compensado_sin_iva)->toBe('2280210.00')
        ->and(CrossingRecord::query()->sole()->iva_factura)->toBeNull();
});

it('validates the manual crossing in Spanish', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm([
        'vendedor' => '80',
        'tipo_cruce' => 'trueque',
        'factura' => '',
        'fecha_cruce' => '25/07/2026',
        'codigo_cliente' => '',
        'razon_social' => '',
        'importe_original' => '0',
        'valor_cruzado' => 'mucho',
        'iva_factura' => '9.000.000',
        'soporte' => null,
    ]))->assertSessionHasErrors([
        'vendedor', 'tipo_cruce', 'factura', 'fecha_cruce', 'codigo_cliente', 'razon_social',
        'importe_original' => 'El valor debe ser mayor que cero.',
        'valor_cruzado' => 'Escribe el valor como 1234567 o 1.234.567.',
        'soporte',
    ]);

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm(['iva_factura' => '9.000.000']))
        ->assertSessionHasErrors(['iva_factura' => 'El IVA no puede ser mayor que el importe original.']);

    expect(CrossingRecord::query()->count())->toBe(0);
});

it('lists the SAP and approved manual crossings of the period to reassign, with their current seller', function () {
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()]);
    $this->sap->send(Dataset::Offsets, [
        SapBatches::crossing(),
        SapBatches::crossing(['linea' => 1, 'estado' => 'anulado']),
        SapBatches::crossing(['numero_cruce' => 9002, 'fecha_cruce' => '2026-08-02']),
    ]);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm());

    $response = $this->actingAs($this->analyst)->get('/comisiones/ajustes/reasignaciones?periodo=2026-07')->assertOk()
        ->assertSee('name="formulario" value="reasignaciones"', false)
        ->assertSee('value="sap-9001-0"', false)
        ->assertSeeText('Factura 8001 · Ferretería El Tornillo SAS · 22/07/2026 · vendedor 14')
        // Annulled, of another period, or a manual crossing still pending: not offered.
        ->assertDontSee('value="sap-9001-1"', false)
        ->assertDontSee('value="sap-9002-0"', false);

    expect($response->getContent())->not->toContain('value="manual-');

    CrossingRecord::query()->where('origen', 'manual')->update(['estado' => 'aprobado']);

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/reasignaciones?periodo=2026-07')->assertOk()
        ->assertSeeText('Factura FV-8123 · Distribuciones La 80 SAS · 25/07/2026 · vendedor 15');
});

it('registers a reassignment of a crossing pending, and refuses one to the seller it already has', function () {
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing()]);

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', reassignmentForm(['codigo' => '14']))
        ->assertSessionHasErrors(['codigo' => 'El cruce ya es del vendedor 14.']);

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', reassignmentForm())
        ->assertRedirect('/comisiones/ajustes/reasignaciones?periodo=2026-07')
        ->assertSessionHas('status', 'Registraste la reasignación de 2026-07. Queda pendiente hasta que otra persona lo apruebe.');

    $reassignment = ReassignmentRecord::query()->sole();

    expect($reassignment->clave_cruce)->toBe('sap-9001-0')
        ->and($reassignment->slp_code)->toBe(15)
        ->and($reassignment->motivo)->toBe('El cliente pasó a la zona de Bogotá en junio')
        ->and($reassignment->estado)->toBe(CaptureStatus::Pending);
    expect(AuditRecord::query()->where('evento', 'comisiones.capture.created')->sole()->sujeto_tipo)->toBe('reasignacion');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/reasignaciones?periodo=2026-07')->assertOk()
        ->assertSee('data-capture="'.$reassignment->id.'"', false)
        ->assertSeeText('El cliente pasó a la zona de Bogotá en junio')
        ->assertSeeText('Factura 8001 · Ferretería El Tornillo SAS · 22/07/2026');
});

it('refuses a reassignment of a crossing outside the period, annulled or unknown', function () {
    $this->sap->send(Dataset::Offsets, [
        SapBatches::crossing(['linea' => 1, 'estado' => 'anulado']),
        SapBatches::crossing(['numero_cruce' => 9002, 'fecha_cruce' => '2026-08-02']),
    ]);

    foreach (['sap-9001-1', 'sap-9002-0', 'sap-1-0', ''] as $key) {
        $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', reassignmentForm(['clave_cruce' => $key]))
            ->assertSessionHasErrors('clave_cruce');
    }

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', reassignmentForm(['clave_cruce' => 'sap-9001-1']))
        ->assertSessionHasErrors(['clave_cruce' => 'Elige un cruce vigente del período.']);
    expect(ReassignmentRecord::query()->count())->toBe(0);
});

it('refuses manual crossings and reassignments in a closed period', function () {
    // A client of code 50 (no minimum, no collection rate): the period closes without blocking alerts.
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client(['slp_code' => 50])]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing()]);
    ClosedPeriods::close($this, $this->sap, $this->analyst, ComisionesUsers::approver());
    $message = 'El período está cerrado. Para corregirlo registra una liquidación complementaria.';

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/cruces?periodo=2026-07')->assertOk()
        ->assertSeeText($message)
        ->assertDontSee('name="formulario" value="cruces"', false);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm())->assertSessionHas('error', $message);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', reassignmentForm())->assertSessionHas('error', $message);

    // August is still open.
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', manualCrossingForm(['fecha_cruce' => '2026-08-01']))->assertSessionHas('status');

    expect(CrossingRecord::query()->where('origen', 'manual')->count())->toBe(1)
        ->and(ReassignmentRecord::query()->count())->toBe(0);
});
