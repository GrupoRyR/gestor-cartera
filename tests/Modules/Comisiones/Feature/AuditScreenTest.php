<?php

use App\Models\User;
use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Audit\AuditLabels;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;

/*
| The audit trail on screen (§7.9, §10): read only, most recent first, 50
| per page, filtered by user, dates (Bogotá, inclusive), event and period.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->audit = app(AuditTrail::class);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();
});

/**
 * Records an event at a Bogotá time.
 *
 * @param  array<string, mixed>  $changes
 * @param  array<string, mixed>  $context
 */
function auditAt(string $at, string $event, ?User $user, ?string $period = null, array $changes = [], array $context = [], ?string $reason = null, ?string $subjectType = null, int|string|null $subjectId = null): AuditRecord
{
    test()->travelTo(CarbonImmutable::parse($at, 'America/Bogota'));

    return app(AuditTrail::class)->record($event, $subjectType, $subjectId, $changes, $context, $period, $user, $reason);
}

it('serves the audit trail at /comisiones/auditoria and shows it in the menu', function () {
    expect(route('comisiones.auditoria.index', absolute: false))->toBe('/comisiones/auditoria');

    $this->actingAs($this->approver)->get('/comisiones/auditoria')->assertOk();

    $registry = app(NavRegistry::class);
    expect(collect($registry->visibleTo($this->approver))->pluck('key')->all())->toContain('comisiones.auditoria')
        ->and(collect($registry->visibleTo(ComisionesUsers::viewer()))->pluck('key')->all())->not->toContain('comisiones.auditoria');
});

it('lets admin, gerente de cartera and the approver read the trail', function (array $roles) {
    $this->actingAs(ComisionesUsers::create($roles))->get('/comisiones/auditoria')->assertOk();
})->with([
    'administrador' => [[Role::Admin->value]],
    'gerente de cartera' => [[Role::PortfolioManager->value]],
    'aprobador' => [['comisiones_aprobador']],
]);

it('forbids the trail to roles without comisiones.audit', function (array $roles) {
    $this->actingAs(ComisionesUsers::create($roles))->get('/comisiones/auditoria')->assertForbidden();
})->with([
    'consulta' => [['comisiones_consulta']],
    'asesor' => [[Role::SalesAdvisor->value]],
    'analista de cartera' => [[Role::PortfolioAnalyst->value]],
    'sin rol' => [[]],
]);

it('lists the events most recent first with their labels, subject, reason and details', function () {
    auditAt('2026-08-01 10:00', 'comisiones.load.imported', null, null, context: ['dataset' => 'ventas', 'filas' => 12], subjectType: 'comisiones_carga', subjectId: 4);
    auditAt('2026-08-03 11:30', 'comisiones.budget.updated', $this->analyst, '2026-08', changes: ['valor' => ['old' => '1000.00', 'new' => '2000.00']], context: ['codigo' => 14], subjectType: 'presupuesto', subjectId: 9);
    auditAt('2026-09-02 16:45', 'comisiones.capture.rejected', $this->approver, '2026-08', reason: 'Falta el soporte firmado', subjectType: 'obr', subjectId: 3);
    auditAt('2026-09-02 17:00', 'comisiones.something.new_thing', $this->approver, '2026-08');

    $response = $this->actingAs($this->approver)->get('/comisiones/auditoria')->assertOk();
    preg_match_all('/data-event="([^"]+)"/', (string) $response->getContent(), $events);

    expect($events[1])->toBe(['comisiones.something.new_thing', 'comisiones.capture.rejected', 'comisiones.budget.updated', 'comisiones.load.imported']);

    $response->assertSeeText('Captura rechazada')
        ->assertSeeText('Presupuesto editado')
        ->assertSeeText('Carga de SAP importada')
        // An event without a label shows its name.
        ->assertSeeText('comisiones.something.new_thing')
        ->assertSeeText('02/09/2026 16:45')
        ->assertSeeText('Beto Ruiz')
        ->assertSeeText('Sistema')
        ->assertSeeText('Falta el soporte firmado')
        ->assertSeeText('OBR #3')
        ->assertSee('<details', false)
        ->assertSeeText('valor')
        ->assertSeeText('1000.00 → 2000.00')
        ->assertSeeText('dataset: ventas')
        ->assertDontSee('name="_method"', false);

    // Read only: nothing in the page content posts (the layout's logout aside).
    $main = Str::between((string) $response->getContent(), '<main', '</main>');

    expect($main)->not->toContain('method="POST"')
        ->and(preg_match('/\p{Lu}{4,}/u', strip_tags($response->getContent())))->toBe(0);
});

it('labels every event the module records', function (string $event) {
    expect(AuditLabels::event($event))->not->toBe($event)
        ->and(preg_match('/\p{Lu}{4,}/u', AuditLabels::event($event)))->toBe(0);
})->with([
    'comisiones.load.imported', 'comisiones.parameter.version_added', 'comisiones.role.assigned', 'comisiones.role.removed',
    'comisiones.budget.updated', 'comisiones.budget.deleted', 'comisiones.budget.imported',
    'comisiones.capture.created', 'comisiones.capture.approved', 'comisiones.capture.rejected', 'comisiones.capture.deleted',
    'comisiones.support.downloaded', 'comisiones.settlement.calculated', 'comisiones.settlement.approved',
    'comisiones.settlement.closed', 'comisiones.settlement.complemented', 'comisiones.export.downloaded',
    'comisiones.report.generated', 'comisiones.report.downloaded',
]);

it('filters by user, dates, event and period, and keeps the filters in the pages', function () {
    auditAt('2026-07-31 23:30', 'comisiones.budget.updated', $this->analyst, '2026-07');
    auditAt('2026-08-01 00:10', 'comisiones.budget.updated', $this->analyst, '2026-08');
    auditAt('2026-08-15 12:00', 'comisiones.budget.deleted', $this->analyst, '2026-08');
    auditAt('2026-08-31 23:50', 'comisiones.budget.updated', $this->approver, '2026-08');
    auditAt('2026-09-01 00:05', 'comisiones.budget.updated', $this->analyst, '2026-09');
    auditAt('2026-08-20 09:00', 'comisiones.load.imported', null);
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));

    $count = fn (string $query): int => substr_count((string) $this->actingAs($this->approver)->get('/comisiones/auditoria?'.$query)->assertOk()->getContent(), 'data-entry=');

    expect($count(''))->toBe(6)
        // Dates are Bogotá days, both ends included.
        ->and($count('desde=2026-08-01&hasta=2026-08-31'))->toBe(4)
        ->and($count('usuario='.$this->analyst->id))->toBe(4)
        ->and($count('usuario=sistema'))->toBe(1)
        ->and($count('evento=comisiones.budget.deleted'))->toBe(1)
        ->and($count('periodo=2026-08'))->toBe(3)
        ->and($count('usuario='.$this->analyst->id.'&periodo=2026-08&evento=comisiones.budget.updated'))->toBe(1)
        // Invalid filters are ignored.
        ->and($count('desde=ayer&periodo=agosto&usuario=x'))->toBe(6);

    // The selects offer what the trail holds.
    $this->actingAs($this->approver)->get('/comisiones/auditoria')
        ->assertSee('<option value="'.$this->analyst->id.'"', false)
        ->assertSee('<option value="sistema"', false)
        ->assertSee('<option value="comisiones.budget.deleted"', false)
        ->assertSee('<option value="2026-07"', false);
});

it('pages the trail by 50 and keeps the query string', function () {
    foreach (range(1, 55) as $minute) {
        auditAt(sprintf('2026-08-10 10:%02d', $minute % 60), 'comisiones.budget.updated', $this->analyst, '2026-08');
    }
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));

    $first = $this->actingAs($this->approver)->get('/comisiones/auditoria?periodo=2026-08')->assertOk();
    expect(substr_count((string) $first->getContent(), 'data-entry='))->toBe(50);
    $first->assertSee('periodo=2026-08&amp;page=2', false);

    $second = $this->actingAs($this->approver)->get('/comisiones/auditoria?periodo=2026-08&page=2')->assertOk();
    expect(substr_count((string) $second->getContent(), 'data-entry='))->toBe(5);
});
