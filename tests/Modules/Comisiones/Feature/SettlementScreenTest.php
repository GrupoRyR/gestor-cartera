<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;
use Tests\Modules\Comisiones\Feature\Concerns\SettlementRows;

/*
| The settlement of a period on screen (§6, §9.7, §10): calculate, approve
| with justifications, close and register a complementary settlement.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();
});

/**
 * Code 14 of July 2026 (Annex 2): 69,915,134 of sales on a budget of
 * 77,241,857, a 524,364 sale commission. With $unknownCode, a sale of
 * code 99, which has no parameters: a blocking alert.
 */
function julySales(SapBatches $sap, int $budgetBy, bool $unknownCode = false): void
{
    $sap->send(Dataset::Sales, array_values(array_filter([
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
        $unknownCode ? SapBatches::sale(['doc_entry' => 5002, 'numero_documento' => 8002, 'slp_code' => 99, 'vendedor' => 'VENDEDOR NUEVO', 'total' => '100000.00']) : null,
    ])));
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $budgetBy]);
}

it('serves the settlement of a period under /comisiones/periodos', function () {
    expect(route('comisiones.periodos.show', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07')
        ->and(route('comisiones.periodos.calcular', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/calcular')
        ->and(route('comisiones.periodos.aprobar', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/aprobar')
        ->and(route('comisiones.periodos.cerrar', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/cerrar')
        ->and(route('comisiones.periodos.complementaria', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/complementaria');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-13')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/julio')->assertNotFound();
});

it('sends guests to the login', function () {
    $this->get('/comisiones/periodos/2026-07')->assertRedirect('/login');
    $this->post('/comisiones/periodos/2026-07/calcular')->assertRedirect('/login');
});

it('forbids the settlement to roles without comisiones.view', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/periodos/2026-07')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/liquidacion')->assertForbidden();
    $this->actingAs($user)->post('/comisiones/periodos/2026-07/calcular')->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('lists every entry of the module in the menu, showing the ones whose route exists', function () {
    $registry = app(NavRegistry::class);

    foreach (['tablero', 'liquidacion', 'presupuesto', 'ajustes', 'parametros', 'auditoria'] as $key) {
        expect($registry->has("comisiones.{$key}"))->toBeTrue();
    }

    $visible = collect($registry->visibleTo($this->analyst))->pluck('key')->all();

    expect($visible)->toContain('comisiones.liquidacion')
        ->and(collect($registry->visibleTo(ComisionesUsers::create([Role::SalesAdvisor->value])))->pluck('key')->filter(fn ($key) => str_starts_with($key, 'comisiones.'))->all())->toBe([]);
});

it('opens the month being settled from the menu entry', function () {
    $this->actingAs($this->analyst)->get('/comisiones/liquidacion')->assertRedirect('/comisiones/periodos/2026-07');
});

it('keeps the menu entry on the month being settled when an older draft was left open', function () {
    SettlementRows::placeholder('2026-03', SettlementStatus::Draft);
    SettlementRows::placeholder('2026-06', SettlementStatus::Approved);

    $this->actingAs($this->analyst)->get('/comisiones/liquidacion')->assertRedirect('/comisiones/periodos/2026-07');
});

it('never holds the menu entry on a future month calculated by mistake', function () {
    SettlementRows::placeholder('2026-12', SettlementStatus::Draft);

    $this->actingAs($this->analyst)->get('/comisiones/liquidacion')->assertRedirect('/comisiones/periodos/2026-07');
});

it('opens a later period from the menu entry while it is open', function () {
    SettlementRows::placeholder('2026-07', SettlementStatus::Closed);
    SettlementRows::placeholder('2026-08', SettlementStatus::Draft);

    $this->actingAs($this->analyst)->get('/comisiones/liquidacion')->assertRedirect('/comisiones/periodos/2026-08');

    SettlementRecord::query()->where('periodo', '2026-08')->update(['estado' => SettlementStatus::Closed->value]);

    $this->actingAs($this->analyst)->get('/comisiones/liquidacion')->assertRedirect('/comisiones/periodos/2026-07');
});

it('offers the analyst to calculate a period never calculated, and only shows it to a viewer', function () {
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Liquidación de julio de 2026')
        ->assertSeeText('Aún no has calculado la liquidación de julio de 2026')
        ->assertSee('action="'.route('comisiones.periodos.calcular', '2026-07').'"', false)
        ->assertSeeText('Calcular liquidación');

    $this->actingAs(ComisionesUsers::viewer())->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Cuando el analista la calcule, la verás aquí.')
        ->assertDontSee('action="'.route('comisiones.periodos.calcular', '2026-07').'"', false);
});

it('calculates a draft and audits it', function () {
    julySales($this->sap, $this->analyst->id);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular')
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('status', 'Calculaste la liquidación de 2026-07: versión 1, revisión 1.');

    $record = SettlementRecord::query()->sole();

    expect($record->estado)->toBe('borrador')
        ->and($record->calculado_por)->toBe('ana.gomez@segurex.com');

    $this->fakes->audit->assertRecorded('comisiones.settlement.calculated', fn (AuditEntry $entry) => $entry->subjectId === $record->id
        && $entry->context['version'] === 1 && $entry->context['periodo'] === '2026-07');
    expect(AuditRecord::query()->where('evento', 'comisiones.settlement.calculated')->where('periodo', '2026-07')->where('user_id', $this->analyst->id)->exists())->toBeTrue();

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Borrador')
        ->assertSeeText('Versión 1 · revisión 1')
        ->assertSeeText(Money::format('524364'))
        ->assertSeeText('03/08/2026 09:00 por Ana Gómez')
        ->assertSeeText('Recalcular');
});

it('keeps the analyst away from the approval', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertDontSee('action="'.route('comisiones.periodos.aprobar', '2026-07').'"', false);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])->assertForbidden();
});

it('refuses an approver who loaded captures of the period, with the guard reason', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'descripcion' => 'Escudos', 'valor_vendido' => '1.00', 'tasa' => '1', 'estado' => 'pendiente', 'created_by' => $this->approver->id]);

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('No puedes aprobar esta liquidación')
        ->assertSeeText('Registraste ventas OBR de 2026-07.')
        ->assertSee('disabled', false);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('error', 'No puedes aprobar la liquidación de 2026-07: quien carga datos o cambia parámetros del período no la aprueba.')
        ->assertSessionHas('razones', ['Registraste ventas OBR de 2026-07.']);

    expect(SettlementRecord::query()->sole()->estado)->toBe('borrador');
});

it('refuses the approval of the user who calculated the version', function () {
    $both = ComisionesUsers::create([Role::PortfolioManager->value, ModuleRoles::APPROVER], 'doble.rol@segurex.com', 'Doble Rol');
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($both)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($both)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Tú calculaste esta versión: otra persona debe aprobarla.');

    $this->actingAs($both)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('error', 'Tú calculaste esta versión: otra persona debe aprobarla.');
});

it('approves a draft once every blocking alert is justified', function () {
    julySales($this->sap, $this->analyst->id, unknownCode: true);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Código de vendedor desconocido')
        ->assertSee('name="justificaciones[0][clave]" value="codigo_desconocido:99"', false)
        ->assertSee('name="revision" value="1"', false);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', [
        'revision' => 1,
        'justificaciones' => [['clave' => 'codigo_desconocido:99', 'texto' => '']],
    ])
        ->assertSessionHasErrors('justificaciones.0.texto')
        ->assertSessionHas('error', 'Falta justificar una alerta bloqueante.');

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', [
        'revision' => 1,
        'justificaciones' => [['clave' => 'codigo_desconocido:99', 'texto' => 'Vendedor nuevo; SAP lo crea en agosto.']],
    ])
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('status', 'Aprobaste la versión 1 de la liquidación de 2026-07.');

    $record = SettlementRecord::query()->sole();

    expect($record->estado)->toBe('aprobada')
        ->and($record->aprobado_por)->toBe('beto.ruiz@segurex.com')
        ->and($record->justificaciones)->toBe(['codigo_desconocido:99' => 'Vendedor nuevo; SAP lo crea en agosto.']);
    $this->fakes->audit->assertRecorded('comisiones.settlement.approved');

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Aprobada (sin cerrar)')
        ->assertSeeText('Vendedor nuevo; SAP lo crea en agosto.')
        ->assertSeeText('03/08/2026 09:00 por Beto Ruiz')
        ->assertSeeText('Cerrar período');
});

it('answers an invalid step in Spanish and still reports it, so a bug never hides behind the message', function () {
    Exceptions::fake();
    julySales($this->sap, budgetBy: $this->analyst->id, unknownCode: true);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    // A justification for something that is not a blocking alert of the version.
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', [
        'revision' => 1,
        'justificaciones' => [
            ['clave' => 'codigo_desconocido:99', 'texto' => 'Vendedor nuevo.'],
            ['clave' => 'sin_presupuesto:14', 'texto' => 'No aplica.'],
        ],
    ])
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('error', 'No pudimos completar el paso: revisa el motivo y las justificaciones e inténtalo de nuevo.');

    Exceptions::assertReported(InvalidArgumentException::class);
    expect(SettlementRecord::query()->sole()->estado)->toBe('borrador');
});

it('asks the approver to review again a draft recalculated after the review', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('error', 'La liquidación se recalculó después de que la revisaste (revisión 1 → 2). Revísala de nuevo.');

    expect(SettlementRecord::query()->sole()->estado)->toBe('borrador');
});

it('closes an approved settlement and refuses to recalculate it', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/cerrar')
        ->assertSessionHas('status', 'Cerraste el período 2026-07. La versión 1 quedó congelada.');

    expect(SettlementRecord::query()->sole()->estado)->toBe('cerrada');
    $this->fakes->audit->assertRecorded('comisiones.settlement.closed');

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular')
        ->assertSessionHas('error', 'La liquidación de 2026-07 está cerrada. Para corregirla, registra una liquidación complementaria.');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Cerrada')
        ->assertDontSee('action="'.route('comisiones.periodos.calcular', '2026-07').'"', false)
        ->assertSeeText('Registrar liquidación complementaria');
});

it('refuses to close a draft and to complement an open period', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/cerrar')
        ->assertSessionHas('error', 'Solo se cierra una liquidación aprobada.');
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/complementaria', ['motivo' => 'Recaudo tardío'])
        ->assertSessionHas('error', 'La liquidación de 2026-07 sigue abierta: recalcúlala en lugar de registrar una complementaria.');
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-08/aprobar', ['revision' => 1])
        ->assertSessionHas('error', 'Todavía no has calculado la liquidación de 2026-08.');
});

it('registers a complementary settlement with its reason and keeps the closed version viewable', function () {
    julySales($this->sap, $this->analyst->id);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/cerrar');

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/complementaria', ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/complementaria', ['motivo' => 'Recaudo de julio que llegó tarde'])
        ->assertSessionHas('status', 'Registraste la versión 2 de 2026-07 como liquidación complementaria. Revísala y apruébala de nuevo.');

    $versions = app(EloquentSettlements::class)->versions(Period::of(2026, 7));

    expect(array_map(fn (SettlementRecord $row) => [$row->version, $row->estado], $versions))->toBe([
        [1, SettlementStatus::Closed->value],
        [2, SettlementStatus::Draft->value],
    ])->and($versions[1]->motivo)->toBe('Recaudo de julio que llegó tarde');
    $this->fakes->audit->assertRecorded('comisiones.settlement.complemented', fn (AuditEntry $entry) => $entry->context['motivo'] === 'Recaudo de julio que llegó tarde');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertViewHas('versions', fn (array $rows) => count($rows) === 2 && ! array_key_exists('resultado', $rows[0]->getAttributes()))
        ->assertSeeText('Versión 2 · revisión 1')
        ->assertSeeText('Recaudo de julio que llegó tarde')
        ->assertSeeText('Ver versión 1');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07?version=1')->assertOk()
        ->assertSeeText('Estás viendo la versión 1. La vigente es la 2.')
        ->assertSeeText('Cerrada')
        ->assertDontSee('action="'.route('comisiones.periodos.calcular', '2026-07').'"', false);

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07?version=7')->assertNotFound();
});
