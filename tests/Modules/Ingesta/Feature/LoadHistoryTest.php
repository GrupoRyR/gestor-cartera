<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Ingesta\Database\Seeders\IngestaPermissionSeeder;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Ingesta\Support\IngestaLoads;
use Tests\Modules\Ingesta\Support\IngestaUsers;

beforeEach(function () {
    $this->seed(IngestaPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:30:00', 'America/Bogota'));
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany);
});

/**
 * An analyst who may see the history.
 */
function ingestaAnalyst(): User
{
    return IngestaUsers::create(Role::PortfolioAnalyst);
}

it('serves the history under /ingesta', function () {
    expect(route('ingesta.index', absolute: false))->toBe('/ingesta')
        ->and(route('ingesta.cargas.show', 7, absolute: false))->toBe('/ingesta/cargas/7');
});

it('sends guests to the sign-in page', function (string $url) {
    $load = IngestaLoads::accepted();

    $this->get(str_replace('{load}', (string) $load->id, $url))->assertRedirect('/login');
})->with(['history' => '/ingesta', 'detail' => '/ingesta/cargas/{load}']);

it('opens the history and the detail for the cartera roles', function (Role $role) {
    $load = IngestaLoads::accepted();
    $user = IngestaUsers::create($role);

    $this->actingAs($user)->get('/ingesta')->assertOk()->assertSeeText('Cargas de datos');
    $this->actingAs($user)->get("/ingesta/cargas/{$load->id}")->assertOk();
})->with(IngestaUsers::allowed());

it('forbids the history and the detail to every other role', function (?Role $role) {
    $load = IngestaLoads::accepted();
    $user = IngestaUsers::create($role);

    $this->actingAs($user)->get('/ingesta')->assertForbidden();
    $this->actingAs($user)->get("/ingesta/cargas/{$load->id}")->assertForbidden();
})->with(IngestaUsers::forbidden());

it('adds Cargas de datos to the administration menu of the cartera roles', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));
    $item = collect(app(NavRegistry::class)->all())->firstWhere('key', 'ingesta.cargas');

    expect($item)->toBeInstanceOf(NavItem::class)
        ->and($item->label)->toBe('Cargas de datos')
        ->and($item->route)->toBe('ingesta.index')
        ->and($item->icon)->toBe('history')
        ->and($item->permission)->toBe('ingesta.view')
        ->and($item->group)->toBe('Administración')
        ->and($item->order)->toBe(920)
        ->and($item->activePattern)->toBe('ingesta.*')
        ->and($keys(ingestaAnalyst()))->toContain('ingesta.cargas')
        ->and($keys(IngestaUsers::create(Role::SalesAdvisor)))->not->toContain('ingesta.cargas');
});

it('lists the loads newest first with their details', function () {
    $uploader = IngestaUsers::create(Role::PortfolioManager, 'Claudia Gómez');
    IngestaLoads::accepted([
        'received_at' => CarbonImmutable::parse('2026-10-15 06:10:00', 'America/Bogota'),
        'row_count' => 1234,
        'total' => '1250000000.50',
        'overdue' => '300000000.00',
    ]);
    IngestaLoads::accepted([
        'dataset' => Dataset::Advisors,
        'company' => CompanyCode::SDC,
        'cutoff_date' => '2026-10-14',
        'source' => LoadSource::Manual,
        'received_by' => $uploader->id,
        'received_at' => CarbonImmutable::parse('2026-10-15 08:45:00', 'America/Bogota'),
        'row_count' => 12,
        'total' => null,
        'overdue' => null,
    ]);
    IngestaLoads::rejected(['rows.3.saldo' => ['La columna saldo es obligatoria.']], [
        'received_at' => CarbonImmutable::parse('2026-10-15 05:00:00', 'America/Bogota'),
    ]);

    $this->actingAs(ingestaAnalyst())->get('/ingesta')
        ->assertOk()
        ->assertSeeTextInOrder([
            '15/10/2026 08:45', 'Asesores', 'SDC', '14/10/2026', 'Carga manual', 'Aceptada', '12', '—', '—', 'Claudia Gómez', 'Ver detalle',
            '15/10/2026 06:10', 'Cartera', 'SGX', '15/10/2026', 'Robot', 'Aceptada', '1.234', "$\u{a0}1.250.000.001", "$\u{a0}300.000.000", 'Ver detalle',
            '15/10/2026 05:00', 'Cartera', 'SGX', '15/10/2026', 'Robot', 'Rechazada', 'Ver detalle',
        ])
        ->assertSee('data-tone="success"', false)
        ->assertSee('data-tone="danger"', false);
});

it('filters by dataset, company and status', function () {
    IngestaLoads::accepted(['batch_id' => 'cartera-sgx', 'row_count' => 111]);
    IngestaLoads::accepted(['batch_id' => 'cartera-sdc', 'company' => CompanyCode::SDC, 'row_count' => 222]);
    IngestaLoads::accepted(['batch_id' => 'asesores-sgx', 'dataset' => Dataset::Advisors, 'row_count' => 333, 'total' => null, 'overdue' => null]);
    IngestaLoads::rejected(['rows' => ['Sin filas.']], ['batch_id' => 'rechazada-sgx', 'row_count' => 444]);

    $rows = function (array $query): array {
        $html = $this->actingAs(ingestaAnalyst())->get(route('ingesta.index', $query))->assertOk()->getContent();
        preg_match_all('/data-batch="([^"]+)"/', (string) $html, $matches);

        return $matches[1];
    };

    expect($rows([]))->toEqualCanonicalizing(['cartera-sgx', 'cartera-sdc', 'asesores-sgx', 'rechazada-sgx'])
        ->and($rows(['lote' => 'cartera']))->toEqualCanonicalizing(['cartera-sgx', 'cartera-sdc', 'rechazada-sgx'])
        ->and($rows(['empresa' => 'SDC']))->toBe(['cartera-sdc'])
        ->and($rows(['estado' => 'rechazada']))->toBe(['rechazada-sgx'])
        ->and($rows(['lote' => 'cartera', 'empresa' => 'SGX', 'estado' => 'aceptada']))->toBe(['cartera-sgx'])
        // Unknown values do not filter.
        ->and($rows(['lote' => 'otro', 'empresa' => 'XYZ', 'estado' => 'x']))->toHaveCount(4);
});

it('keeps the chosen filters in the form', function () {
    $this->actingAs(ingestaAnalyst())->get('/ingesta?lote=asesores&empresa=SDC&estado=rechazada')
        ->assertOk()
        ->assertSee('<option value="asesores" selected>', false)
        ->assertSee('<option value="SDC" selected>', false)
        ->assertSee('<option value="rechazada" selected>', false);
});

it('pages the history by 25', function () {
    foreach (range(1, 26) as $minute) {
        IngestaLoads::accepted(['batch_id' => "lote-{$minute}", 'received_at' => CarbonImmutable::parse('2026-10-15 06:00:00', 'America/Bogota')->addMinutes($minute)]);
    }

    $first = $this->actingAs(ingestaAnalyst())->get('/ingesta')->assertOk()->getContent();
    $second = $this->actingAs(ingestaAnalyst())->get('/ingesta?page=2')->assertOk()->getContent();

    expect(substr_count((string) $first, 'data-batch='))->toBe(25)
        ->and((string) $first)->toContain('data-batch="lote-26"')->not->toContain('data-batch="lote-1"')
        ->and(substr_count((string) $second, 'data-batch='))->toBe(1)
        ->and((string) $second)->toContain('data-batch="lote-1"');
});

it('shows an empty state without loads and another one when the filters match none', function () {
    $this->actingAs(ingestaAnalyst())->get('/ingesta')->assertOk()->assertSeeText('Aún no hay cargas');

    IngestaLoads::accepted();

    $this->actingAs(ingestaAnalyst())->get('/ingesta?empresa=SDC')->assertOk()->assertSeeText('No hay cargas con estos filtros');
});

it('summarizes the current cartera of each alert company', function () {
    IngestaLoads::accepted(['cutoff_date' => '2026-10-14', 'total' => '900.00', 'overdue' => '100.00']);
    IngestaLoads::accepted(['cutoff_date' => '2026-10-15', 'source' => LoadSource::Manual, 'total' => '2500000.00', 'overdue' => '750000.00']);
    IngestaLoads::accepted(['company' => CompanyCode::SDC, 'total' => '9999.00']);

    $this->actingAs(ingestaAnalyst())->get('/ingesta')
        ->assertOk()
        ->assertSeeTextInOrder(['Cartera vigente de Segurex Latam', '15/10/2026', 'Carga manual', "$\u{a0}2.500.000", "$\u{a0}750.000"])
        ->assertDontSeeText('Cartera vigente de Soluciones y Derivados');
});

it('says when an alert company has no accepted cartera yet', function () {
    $this->actingAs(ingestaAnalyst())->get('/ingesta')
        ->assertOk()
        ->assertSeeText('Cartera vigente de Segurex Latam')
        ->assertSeeText('Aún no hay una carga de cartera aceptada.');
});

it('warns once the deadline passes without the robot cartera of today', function (string $time, array $loads, bool $warns) {
    $this->travelTo(CarbonImmutable::parse("2026-10-15 {$time}", 'America/Bogota'));

    foreach ($loads as $load) {
        $load();
    }

    $response = $this->actingAs(ingestaAnalyst())->get('/ingesta')->assertOk();

    $warns
        ? $response->assertSeeText('Falta la cartera de hoy de Segurex Latam')->assertSee('data-tone="warning"', false)
        : $response->assertDontSeeText('Falta la cartera de hoy');
})->with([
    'before the deadline' => ['06:59:59', [], false],
    'at the deadline' => ['07:00:00', [], true],
    'robot load of today' => ['09:30:00', [fn () => IngestaLoads::accepted()], false],
    'only a manual load' => ['09:30:00', [fn () => IngestaLoads::accepted(['source' => LoadSource::Manual])], true],
    'only yesterday' => ['09:30:00', [fn () => IngestaLoads::accepted(['cutoff_date' => '2026-10-14'])], true],
    'only rejected' => ['09:30:00', [fn () => IngestaLoads::rejected(['rows' => ['Sin filas.']])], true],
    'only another company' => ['09:30:00', [fn () => IngestaLoads::accepted(['company' => CompanyCode::SDC])], true],
    'only another dataset' => ['09:30:00', [fn () => IngestaLoads::accepted(['dataset' => Dataset::Advisors])], true],
]);

it('follows the configured deadline and companies', function () {
    config(['ingesta.alert_deadline' => '10:00', 'ingesta.alert_companies' => ['SGX', 'SDC']]);

    $this->actingAs(ingestaAnalyst())->get('/ingesta')->assertOk()->assertDontSeeText('Falta la cartera de hoy');

    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'America/Bogota'));

    $this->actingAs(ingestaAnalyst())->get('/ingesta')
        ->assertOk()
        ->assertSeeText('Falta la cartera de hoy de Segurex Latam')
        ->assertSeeText('Falta la cartera de hoy de Soluciones y Derivados')
        ->assertSeeText('Cartera vigente de Soluciones y Derivados');
});

it('ships SGX as the only alert company', function () {
    expect(config('ingesta.alert_companies'))->toBe(['SGX']);
});

it('shows the details and totals of an accepted cartera load', function () {
    $uploader = IngestaUsers::create(Role::PortfolioManager, 'Claudia Gómez');
    $load = IngestaLoads::accepted([
        'batch_id' => 'manual-cartera-SGX-2026-10-15-0123456789abcdef',
        'source' => LoadSource::Manual,
        'received_by' => $uploader->id,
        'received_at' => CarbonImmutable::parse('2026-10-15 08:45:00', 'America/Bogota'),
        'row_count' => 1234,
    ]);

    $this->actingAs(ingestaAnalyst())->get("/ingesta/cargas/{$load->id}")
        ->assertOk()
        ->assertSeeTextInOrder([
            'Cartera con corte 15/10/2026', 'Segurex Latam',
            'Aceptada', 'Carga manual', '1.234', '15/10/2026 08:45', 'Claudia Gómez', 'manual-cartera-SGX-2026-10-15-0123456789abcdef',
            'Saldo total', "$\u{a0}1.250.000.001", 'Saldo vencido', "$\u{a0}300.000.000",
        ])
        ->assertDontSeeText('Errores por campo');
});

it('lists the errors of a rejected load', function () {
    $load = IngestaLoads::rejected([
        'batch_id' => ['El identificador del lote no puede tener más de 120 caracteres.'],
        'rows.3' => ['A la fila le faltan estas columnas: saldo.'],
        'rows.4.dias' => ['La columna dias debe ser un número entero.', 'Otro mensaje.'],
    ]);

    $this->actingAs(ingestaAnalyst())->get("/ingesta/cargas/{$load->id}")
        ->assertOk()
        ->assertSeeText('Rechazada')
        ->assertSeeText('El lote tiene 3 errores.')
        ->assertSeeTextInOrder([
            'Errores por campo',
            'Identificador del lote', 'El identificador del lote no puede tener más de 120 caracteres.',
            'Fila 3', 'A la fila le faltan estas columnas: saldo.',
            'Fila 4, columna dias', 'La columna dias debe ser un número entero.', 'Otro mensaje.',
        ])
        ->assertDontSeeText('Saldo total');
});

it('limits the history to the companies the user may see', function () {
    config(['ingesta.alert_companies' => ['SGX', 'SDC']]);
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
    $user = ingestaAnalyst();
    $sgx = IngestaLoads::accepted(['batch_id' => 'cartera-sgx']);
    $sdc = IngestaLoads::accepted(['batch_id' => 'cartera-sdc', 'company' => CompanyCode::SDC]);

    $this->actingAs($user)->get('/ingesta')
        ->assertOk()
        ->assertSee('data-batch="cartera-sgx"', false)
        ->assertDontSee('data-batch="cartera-sdc"', false)
        ->assertSee('<option value="SGX"', false)
        ->assertDontSee('value="SDC"', false)
        ->assertSeeText('Cartera vigente de Segurex Latam')
        // Neither the current cartera nor the missing-load warning of SDC.
        ->assertDontSeeText('Soluciones y Derivados');

    // A company the user may not see does not filter, like an unknown value.
    $this->actingAs($user)->get('/ingesta?empresa=SDC')
        ->assertOk()
        ->assertSee('data-batch="cartera-sgx"', false)
        ->assertDontSee('data-batch="cartera-sdc"', false);

    $this->actingAs($user)->get("/ingesta/cargas/{$sgx->id}")->assertOk();
    $this->actingAs($user)->get("/ingesta/cargas/{$sdc->id}")->assertNotFound();
});

it('answers 404 for an unknown load', function (string $id) {
    $this->actingAs(ingestaAnalyst())->get("/ingesta/cargas/{$id}")->assertNotFound();
})->with(['missing' => ['999'], 'not a number' => ['abc']]);

it('does not offer totals for a load of another dataset', function () {
    $load = IngestaLoads::accepted(['dataset' => Dataset::Advisors, 'total' => null, 'overdue' => null, 'status' => LoadStatus::Accepted]);

    $this->actingAs(ingestaAnalyst())->get("/ingesta/cargas/{$load->id}")
        ->assertOk()
        ->assertSeeText('Asesores con corte 15/10/2026')
        ->assertDontSeeText('Saldo total');
});
