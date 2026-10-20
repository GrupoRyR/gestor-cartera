<?php

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The forms of «Parámetros» (§7.2): each change is a new version from a
| date, prefilled from the version in force, with its motive. The version
| in force ends the day before; a closed period is never touched.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->manager = ComisionesUsers::analyst();
});

/**
 * The form of a version of code 50 (sales only, no minimum).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function codeVersionForm(array $overrides = []): array
{
    return array_replace([
        'clave' => '50',
        'nombre' => 'Andrea Hincapié',
        'persona_nombre' => 'Andrea Hincapié',
        'persona_id' => 'andrea-hincapie',
        'tasa_venta' => '2',
        'tasa_recaudo' => '0',
        'cumplimiento_minimo' => '0',
        'alcance' => 'regular',
        'usa_tramos' => '1',
        'vigente_desde' => '2026-11-01',
        'motivo' => 'Nueva tasa de venta del código 50',
    ], $overrides);
}

/**
 * The form of the tranche table: §5.3 with the first tranche up to 60 days.
 *
 * @param  array<int, array<string, string>>  $rows
 * @return array<string, mixed>
 */
function trancheVersionForm(?array $rows = null, array $overrides = []): array
{
    return array_replace([
        'tramos' => $rows ?? [
            ['desde' => '0', 'hasta' => '60', 'participacion' => '100'],
            ['desde' => '61', 'hasta' => '80', 'participacion' => '90'],
            ['desde' => '81', 'hasta' => '95', 'participacion' => '80'],
            ['desde' => '96', 'hasta' => '125', 'participacion' => '50'],
            ['desde' => '126', 'hasta' => '', 'participacion' => '0'],
            ['desde' => '', 'hasta' => '', 'participacion' => ''],
            ['desde' => '', 'hasta' => '', 'participacion' => ''],
        ],
        'vigente_desde' => '2026-11-01',
        'motivo' => 'El primer tramo pasa a 60 días',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function optionsVersionForm(array $overrides = []): array
{
    return array_replace([
        'base_venta' => 'reportado',
        'iva_venta' => '19',
        'notas_credito' => 'netear',
        'ubicacion_director' => 'aparte',
        'vendedor_cruce' => 'fecha_cruce',
        'alcance_cumplimiento' => 'codigo',
        'moneda' => 'reportado',
        'vigente_desde' => '2026-11-01',
        'motivo' => 'Respuesta de la gerencia a los puntos 14',
    ], $overrides);
}

/**
 * The stored data of the version of a key that starts on the day.
 *
 * @return array<string, mixed>
 */
function storedVersion(string $type, string $key, string $from): array
{
    return ParameterRecord::query()->where('tipo', $type)->where('clave', $key)->whereDate('vigente_desde', $from)->sole()->datos;
}

it('serves the forms under /comisiones/parametros/{tipo}/nueva and stores per kind', function () {
    expect(route('comisiones.parametros.create', ['tipo' => 'codigo', 'clave' => '14'], absolute: false))->toBe('/comisiones/parametros/codigo/nueva?clave=14')
        ->and(route('comisiones.parametros.create', ['tipo' => 'tramos'], absolute: false))->toBe('/comisiones/parametros/tramos/nueva')
        ->and(route('comisiones.parametros.codigo.store', absolute: false))->toBe('/comisiones/parametros/codigo')
        ->and(route('comisiones.parametros.tramos.store', absolute: false))->toBe('/comisiones/parametros/tramos')
        ->and(route('comisiones.parametros.equipo.store', absolute: false))->toBe('/comisiones/parametros/equipo')
        ->and(route('comisiones.parametros.derivada.store', absolute: false))->toBe('/comisiones/parametros/derivada')
        ->and(route('comisiones.parametros.opciones.store', absolute: false))->toBe('/comisiones/parametros/opciones');

    $this->actingAs($this->manager)->get('/comisiones/parametros/codigo/nueva?clave=999')->assertNotFound();
});

it('sends guests to the login', function () {
    $this->get('/comisiones/parametros/codigo/nueva?clave=14')->assertRedirect('/login');
    $this->post('/comisiones/parametros/codigo', codeVersionForm())->assertRedirect('/login');
});

it('forbids the forms to roles without comisiones.parameters', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/parametros/codigo/nueva?clave=14')->assertForbidden();
    $this->actingAs($user)->post('/comisiones/parametros/codigo', codeVersionForm())->assertForbidden();
    $this->actingAs($user)->post('/comisiones/parametros/tramos', trancheVersionForm())->assertForbidden();
    $this->actingAs($user)->post('/comisiones/parametros/opciones', optionsVersionForm())->assertForbidden();

    expect(ParameterRecord::query()->whereNotNull('created_by')->count())->toBe(0);
})->with([
    'aprobador' => [[ModuleRoles::APPROVER]],
    'consulta' => [[ModuleRoles::VIEWER]],
    'asesor' => [[Role::SalesAdvisor->value]],
]);

it('lets the admin add a version too', function () {
    $admin = ComisionesUsers::create([Role::Admin->value], 'admin@segurex.com', 'Alicia Admin');

    $this->actingAs($admin)->get('/comisiones/parametros/codigo/nueva?clave=50')->assertOk();
    $this->actingAs($admin)->post('/comisiones/parametros/codigo', codeVersionForm())->assertRedirect('/comisiones/parametros/codigo/50');

    expect(ParameterRecord::query()->where('clave', '50')->whereNotNull('created_by')->sole()->created_by)->toBe($admin->id);
});

it('prefills a new version of a code from the version in force', function () {
    $response = $this->actingAs($this->manager)->get('/comisiones/parametros/codigo/nueva?clave=16')->assertOk()
        ->assertSeeText('Nueva versión del código 16');

    $html = Html::parse($response->getContent());
    $value = fn (string $name) => $html->one("//*[@name='{$name}']")->getAttribute('value');

    expect($value('clave'))->toBe('16')
        ->and($html->one("//input[@name='clave']")->getAttribute('type'))->toBe('hidden')
        ->and($value('nombre'))->toBe('Carolina Ramírez')
        ->and($value('persona_nombre'))->toBe('Carolina Ramírez')
        ->and($value('persona_id'))->toBe('carolina-ramirez')
        ->and($value('tasa_venta'))->toBe('1,25')
        ->and($value('tasa_recaudo'))->toBe('1,25')
        ->and($value('cumplimiento_minimo'))->toBe('80')
        ->and($html->one("//select[@name='alcance']/option[@selected]")->getAttribute('value'))->toBe('regular')
        ->and($html->one("//input[@name='usa_tramos']")->hasAttribute('checked'))->toBeTrue()
        // No closed period: the month in course by default.
        ->and($value('vigente_desde'))->toBe('2026-10-01')
        ->and($html->count("//input[@name='vigente_desde'][@min]"))->toBe(0);

    $new = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/codigo/nueva')->assertOk()->assertSeeText('Nuevo código')->getContent());

    expect($new->one("//input[@name='clave']")->getAttribute('type'))->toBe('text')
        ->and($new->one("//input[@name='clave']")->getAttribute('value'))->toBe('');
});

it('adds a version of a code that ends the one in force and is used from its month on', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00', 'America/Bogota'));

    $this->actingAs($this->manager)->from('/comisiones/parametros/codigo/nueva?clave=50')
        ->post('/comisiones/parametros/codigo', codeVersionForm())
        ->assertRedirect('/comisiones/parametros/codigo/50')
        ->assertSessionHas('status', 'Guardaste la versión del código 50 que rige desde el 01/11/2026.');

    expect(ParameterRecord::query()->where('tipo', 'codigo')->where('clave', '50')->orderBy('vigente_desde')->get()
        ->map(fn (ParameterRecord $row) => [$row->vigente_desde->format('Y-m-d'), $row->vigente_hasta?->format('Y-m-d')])->all())
        ->toBe([['2026-01-01', '2026-10-31'], ['2026-11-01', null]])
        ->and(storedVersion('codigo', '50', '2026-11-01'))->toBe([
            'nombre' => 'Andrea Hincapié',
            'persona' => ['id' => 'andrea-hincapie', 'nombre' => 'Andrea Hincapié'],
            'tasa_venta' => '2',
            'tasa_recaudo' => '0',
            'cumplimiento_minimo' => '0',
            'alcance' => 'regular',
            'usa_tramos' => true,
        ]);

    // The audit keeps the old and the new values, the author and the motive.
    $this->fakes->audit->assertRecorded('comisiones.parameter.version_added', fn (AuditEntry $entry) => $entry->changes['datos']['old']['tasa_venta'] === '1'
        && $entry->changes['datos']['new']['tasa_venta'] === '2'
        && $entry->changes['vigente_hasta_anterior'] === ['old' => null, 'new' => '2026-10-31']);

    $audit = AuditRecord::query()->where('evento', 'comisiones.parameter.version_added')->sole();

    expect($audit->usuario)->toBe('Ana Gómez')
        ->and($audit->motivo)->toBe('Nueva tasa de venta del código 50');

    // One sale of 1,000,000 pesos of code 50 in October and another in November.
    $sap = new SapBatches($this->fakes->ingesta);
    $sap->send(Dataset::Sales, [
        SapBatches::sale(['doc_entry' => 6001, 'numero_documento' => 9001, 'slp_code' => 50, 'vendedor' => 'ANDREA HINCAPIE', 'fecha_documento' => '2026-10-15', 'fecha_contabilizacion' => '2026-10-15']),
        SapBatches::sale(['doc_entry' => 6002, 'numero_documento' => 9002, 'slp_code' => 50, 'vendedor' => 'ANDREA HINCAPIE', 'fecha_documento' => '2026-11-15', 'fecha_contabilizacion' => '2026-11-15']),
    ], '2026-11-30');

    $this->actingAs($this->manager)->post('/comisiones/periodos/2026-10/calcular');
    $this->actingAs($this->manager)->post('/comisiones/periodos/2026-11/calcular');

    $settlements = app(Settlements::class);

    expect($settlements->latest(Period::of(2026, 10))?->result->code(50)?->saleCommission->amount())->toBe('10000')
        ->and($settlements->latest(Period::of(2026, 11))?->result->code(50)?->saleCommission->amount())->toBe('20000');
});

it('adds a new seller code with rates typed with a comma and the person taken from the name', function () {
    $this->actingAs($this->manager)->from('/comisiones/parametros/codigo/nueva')
        ->post('/comisiones/parametros/codigo', codeVersionForm([
            'clave' => '120',
            'nombre' => 'Laura Pérez',
            'persona_nombre' => 'Laura Pérez',
            'persona_id' => '',
            'tasa_venta' => '0,5',
            'tasa_recaudo' => '0.5 %',
            'cumplimiento_minimo' => '85',
            'usa_tramos' => null,
            'motivo' => 'Código nuevo creado en SAP',
        ]))
        ->assertRedirect('/comisiones/parametros/codigo/120')
        ->assertSessionHas('status', 'Guardaste la versión del código 120 que rige desde el 01/11/2026.');

    expect(storedVersion('codigo', '120', '2026-11-01'))->toBe([
        'nombre' => 'Laura Pérez',
        'persona' => ['id' => 'laura-perez', 'nombre' => 'Laura Pérez'],
        'tasa_venta' => '0.5',
        'tasa_recaudo' => '0.5',
        'cumplimiento_minimo' => '85',
        'alcance' => 'regular',
        'usa_tramos' => false,
    ]);

    // Without a person the code is paid as itself.
    $this->actingAs($this->manager)->from('/comisiones/parametros/codigo/nueva')
        ->post('/comisiones/parametros/codigo', codeVersionForm(['clave' => '121', 'nombre' => 'Código 121', 'persona_nombre' => '', 'persona_id' => '']))
        ->assertRedirect('/comisiones/parametros/codigo/121');

    expect(storedVersion('codigo', '121', '2026-11-01')['persona'])->toBeNull()
        ->and(app(CommissionParameters::class)->book()->forPeriod(Period::of(2026, 11))->code(121)?->name)->toBe('Código 121');
});

it('refuses a version that starts inside a closed period, keeping what was typed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    ClosedPeriods::close($this, new SapBatches($this->fakes->ingesta), $this->manager, ComisionesUsers::approver());

    $form = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/codigo/nueva?clave=14')->assertOk()
        ->assertSeeText('Las versiones nuevas deben empezar después del 31/07/2026, el fin del último período cerrado (julio de 2026).')
        ->getContent());

    expect($form->one("//input[@name='vigente_desde']")->getAttribute('value'))->toBe('2026-08-01')
        ->and($form->one("//input[@name='vigente_desde']")->getAttribute('min'))->toBe('2026-08-01');

    $this->actingAs($this->manager)->from('/comisiones/parametros/codigo/nueva?clave=14')
        ->post('/comisiones/parametros/codigo', codeVersionForm(['clave' => '14', 'nombre' => 'Mónica Rivera', 'vigente_desde' => '2026-07-15', 'motivo' => 'Corrección tardía']))
        ->assertRedirect('/comisiones/parametros/codigo/nueva?clave=14')
        ->assertSessionHasErrors(['version' => 'El período 2026-07 ya está cerrado: la versión debe empezar el 01/08/2026 o después.'])
        ->assertSessionHasInput('motivo', 'Corrección tardía');

    expect(ParameterRecord::query()->where('clave', '14')->count())->toBe(1);

    // The form comes back with the reason and what was typed.
    $this->actingAs($this->manager)->followingRedirects()->from('/comisiones/parametros/codigo/nueva?clave=14')
        ->post('/comisiones/parametros/codigo', codeVersionForm(['clave' => '14', 'nombre' => 'Mónica Rivera', 'vigente_desde' => '2026-07-15', 'motivo' => 'Corrección tardía']))
        ->assertOk()
        ->assertSeeText('No se guardó la versión')
        ->assertSeeText('El período 2026-07 ya está cerrado: la versión debe empezar el 01/08/2026 o después.')
        ->assertSeeText('Corrección tardía');
});

it('validates rates, dates and the motive with Spanish messages', function () {
    $this->actingAs($this->manager)->from('/comisiones/parametros/codigo/nueva?clave=50')
        ->post('/comisiones/parametros/codigo', codeVersionForm([
            'tasa_venta' => '150',
            'tasa_recaudo' => 'mucho',
            'cumplimiento_minimo' => '-5',
            'alcance' => 'otro',
            'vigente_desde' => '2026-11-31',
            'motivo' => '   ',
            'persona_id' => 'Andrea Hincapié',
        ]))
        ->assertRedirect('/comisiones/parametros/codigo/nueva?clave=50')
        ->assertSessionHasErrors([
            'tasa_venta' => 'La tasa de venta debe ser un porcentaje entre 0 y 100, por ejemplo 0,75.',
            'tasa_recaudo' => 'La tasa de recaudo debe ser un porcentaje entre 0 y 100, por ejemplo 0,75.',
            'cumplimiento_minimo' => 'El mínimo de cumplimiento debe ser un porcentaje entre 0 y 100, por ejemplo 90.',
            'motivo' => 'Escribe el motivo del cambio.',
            'persona_id' => 'La clave de la persona va en minúsculas, sin espacios ni tildes, por ejemplo harold-marin.',
        ])
        ->assertSessionHasErrors(['alcance', 'vigente_desde']);

    expect(ParameterRecord::query()->whereNotNull('created_by')->count())->toBe(0);
    $this->fakes->audit->assertNothingRecorded();
});

it('edits the tranche table, dropping the empty rows', function () {
    $form = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/tramos/nueva')->assertOk()
        ->assertSeeText('Nueva versión de la tabla de tramos')
        ->getContent());

    // The five rows in force plus three empty ones.
    expect($form->count("//input[starts-with(@name, 'tramos[') and contains(@name, '[desde]')]"))->toBe(8)
        ->and($form->one("//input[@name='tramos[0][hasta]']")->getAttribute('value'))->toBe('65')
        ->and($form->one("//input[@name='tramos[4][hasta]']")->getAttribute('value'))->toBe('')
        ->and($form->one("//input[@name='tramos[3][participacion]']")->getAttribute('value'))->toBe('50')
        ->and($form->one("//input[@name='tramos[7][desde]']")->getAttribute('value'))->toBe('');

    $this->actingAs($this->manager)->from('/comisiones/parametros/tramos/nueva')
        ->post('/comisiones/parametros/tramos', trancheVersionForm())
        ->assertRedirect('/comisiones/parametros/tramos/tramos')
        ->assertSessionHas('status', 'Guardaste la versión de la tabla de tramos que rige desde el 01/11/2026.');

    expect(storedVersion('tramos', 'tramos', '2026-11-01'))->toBe(['tramos' => [
        ['desde' => 0, 'hasta' => 60, 'participacion' => '100'],
        ['desde' => 61, 'hasta' => 80, 'participacion' => '90'],
        ['desde' => 81, 'hasta' => 95, 'participacion' => '80'],
        ['desde' => 96, 'hasta' => 125, 'participacion' => '50'],
        ['desde' => 126, 'hasta' => null, 'participacion' => '0'],
    ]]);

    expect(app(CommissionParameters::class)->book()->forPeriod(Period::of(2026, 11))->tranches()->shareFor(62)->percent())->toBe('90')
        ->and(app(CommissionParameters::class)->book()->forPeriod(Period::of(2026, 10))->tranches()->shareFor(62)->percent())->toBe('100');
});

it('surfaces overlapping or incomplete tranches', function () {
    // The engine refuses tranches that overlap.
    $this->actingAs($this->manager)->from('/comisiones/parametros/tramos/nueva')
        ->post('/comisiones/parametros/tramos', trancheVersionForm([
            ['desde' => '0', 'hasta' => '65', 'participacion' => '100'],
            ['desde' => '60', 'hasta' => '', 'participacion' => '0'],
        ]))
        ->assertRedirect('/comisiones/parametros/tramos/nueva')
        ->assertSessionHasErrors(['version' => 'Los valores de la versión no son válidos. Revisa los porcentajes, los días y los códigos.'])
        ->assertSessionHasInput('tramos.1.desde', '60');

    // A row without its share, a share above 100 % and no rows at all.
    $this->actingAs($this->manager)->from('/comisiones/parametros/tramos/nueva')
        ->post('/comisiones/parametros/tramos', trancheVersionForm([
            ['desde' => '0', 'hasta' => '65', 'participacion' => '120'],
            ['desde' => '66', 'hasta' => '', 'participacion' => ''],
        ]))
        ->assertSessionHasErrors(['tramos.0.participacion', 'tramos.1.participacion']);

    $this->actingAs($this->manager)->from('/comisiones/parametros/tramos/nueva')
        ->post('/comisiones/parametros/tramos', trancheVersionForm([['desde' => '', 'hasta' => '', 'participacion' => '']]))
        ->assertSessionHasErrors(['tramos' => 'Escribe al menos un tramo.']);

    expect(ParameterRecord::query()->where('tipo', 'tramos')->count())->toBe(1);
});

it('edits a director team and adds a new one', function () {
    $form = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/equipo/nueva?clave=distribucion')->assertOk()
        ->assertSeeText('Nueva versión del equipo Distribución')
        ->getContent());

    expect($form->one("//input[@name='miembros']")->getAttribute('value'))->toBe('14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69')
        ->and($form->one("//input[@name='tasa']")->getAttribute('value'))->toBe('0,2')
        ->and($form->one("//input[@name='director_nombre']")->getAttribute('value'))->toBe('Jessika Quintero')
        ->and($form->one("//input[@name='codigo_asociado']")->getAttribute('value'))->toBe('53');

    $team = [
        'clave' => 'distribucion',
        'canal' => 'Distribución',
        'director_nombre' => 'Jessika Quintero',
        'director_id' => 'jessika-quintero',
        'codigo_asociado' => '53',
        'tasa' => '0,25',
        'miembros' => '14, 15,16 17',
        'vigente_desde' => '2026-11-01',
        'motivo' => 'El equipo se reduce',
    ];

    $this->actingAs($this->manager)->from('/comisiones/parametros/equipo/nueva?clave=distribucion')
        ->post('/comisiones/parametros/equipo', $team)
        ->assertRedirect('/comisiones/parametros/equipo/distribucion')
        ->assertSessionHas('status', 'Guardaste la versión del equipo Distribución que rige desde el 01/11/2026.');

    expect(storedVersion('equipo', 'distribucion', '2026-11-01'))->toBe([
        'canal' => 'Distribución',
        'director' => ['id' => 'jessika-quintero', 'nombre' => 'Jessika Quintero'],
        'codigo_asociado' => 53,
        'tasa' => '0.25',
        'miembros' => [14, 15, 16, 17],
    ]);

    // A new team without associated code; the director id comes from the name.
    $this->actingAs($this->manager)->from('/comisiones/parametros/equipo/nueva')
        ->post('/comisiones/parametros/equipo', [...$team, 'clave' => 'retail', 'canal' => 'Retail', 'director_id' => '', 'codigo_asociado' => '', 'miembros' => '84', 'motivo' => 'Equipo nuevo'])
        ->assertRedirect('/comisiones/parametros/equipo/retail');

    expect(storedVersion('equipo', 'retail', '2026-11-01')['codigo_asociado'])->toBeNull()
        ->and(storedVersion('equipo', 'retail', '2026-11-01')['director'])->toBe(['id' => 'jessika-quintero', 'nombre' => 'Jessika Quintero']);

    // Codes that are not numbers or repeat, and a key that is a plain number.
    $this->actingAs($this->manager)->from('/comisiones/parametros/equipo/nueva')
        ->post('/comisiones/parametros/equipo', [...$team, 'clave' => '77', 'miembros' => '14, 14, abc'])
        ->assertSessionHasErrors(['clave', 'miembros']);
});

it('edits a derived commission and refuses a key that reads as a code', function () {
    $form = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/derivada/nueva?clave=85.1')->assertOk()
        ->assertSeeText('Nueva versión de la comisión derivada 85.1')
        ->getContent());

    expect($form->one("//select[@name='base']/option[@selected]")->getAttribute('value'))->toBe('recaudo')
        ->and($form->one("//input[@name='tasa']")->getAttribute('value'))->toBe('1');

    $derived = [
        'clave' => '85.1',
        'etiqueta' => 'Sergio Caballero (recaudo)',
        'persona_nombre' => 'Sergio Caballero',
        'persona_id' => 'sergio-caballero',
        'codigo_origen' => '85',
        'base' => 'recaudo',
        'tasa' => '1,2',
        'vigente_desde' => '2026-11-01',
        'motivo' => 'Nueva tasa del 85.1',
    ];

    $this->actingAs($this->manager)->from('/comisiones/parametros/derivada/nueva?clave=85.1')
        ->post('/comisiones/parametros/derivada', $derived)
        ->assertRedirect('/comisiones/parametros/derivada/85.1')
        ->assertSessionHas('status', 'Guardaste la versión de la comisión derivada 85.1 que rige desde el 01/11/2026.');

    expect(storedVersion('derivada', '85.1', '2026-11-01'))->toBe([
        'etiqueta' => 'Sergio Caballero (recaudo)',
        'persona' => ['id' => 'sergio-caballero', 'nombre' => 'Sergio Caballero'],
        'codigo_origen' => 85,
        'base' => 'recaudo',
        'tasa' => '1.2',
    ]);

    $this->actingAs($this->manager)->from('/comisiones/parametros/derivada/nueva')
        ->post('/comisiones/parametros/derivada', [...$derived, 'clave' => '86'])
        ->assertSessionHasErrors(['clave' => 'La clave no puede ser solo un número: se confundiría con un código de vendedor.']);
});

it('stores the settlement options and shows them back', function () {
    $form = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/opciones/nueva')->assertOk()
        ->assertSeeText('Nueva versión de las opciones de liquidación')
        ->assertSeeText('Solo cuenta con «Valor con IVA (se descuenta)». Si lo dejas vacío, es 19 %.')
        ->getContent());

    // No version stored: the defaults.
    expect($form->one("//select[@name='alcance_cumplimiento']/option[@selected]")->getAttribute('value'))->toBe('codigo')
        ->and($form->one("//input[@name='iva_venta']")->getAttribute('value'))->toBe('19')
        ->and($form->one("//input[@name='cruces_en_derivadas']")->hasAttribute('checked'))->toBeFalse();

    $this->actingAs($this->manager)->from('/comisiones/parametros/opciones/nueva')
        ->post('/comisiones/parametros/opciones', optionsVersionForm([
            'base_venta' => 'con_iva',
            'iva_venta' => '',
            'cruces_en_derivadas' => '1',
            'alcance_cumplimiento' => 'persona',
        ]))
        ->assertRedirect('/comisiones/parametros/opciones/opciones')
        ->assertSessionHas('status', 'Guardaste la versión de las opciones de liquidación que rige desde el 01/11/2026.');

    expect(storedVersion('opciones', 'opciones', '2026-11-01'))->toBe([
        'base_venta' => 'con_iva',
        'iva_venta' => '19',
        'notas_credito' => 'netear',
        'cruces_en_derivadas' => true,
        'cruces_en_directores' => false,
        'ubicacion_director' => 'aparte',
        'vendedor_cruce' => 'fecha_cruce',
        'alcance_cumplimiento' => 'persona',
        'moneda' => 'reportado',
    ]);

    $november = app(CommissionParameters::class)->book()->forPeriod(Period::of(2026, 11))->options();

    expect($november->complianceScope)->toBe(ComplianceScope::Person)
        ->and($november->crossingsInDerivedCollections)->toBeTrue()
        ->and(app(CommissionParameters::class)->book()->forPeriod(Period::of(2026, 10))->options()->crossingsInDerivedCollections)->toBeFalse();

    $index = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/opciones?fecha=2026-11-01')->assertOk()->getContent());

    expect($index->text("//tr[@data-key='alcance_cumplimiento']/td[@data-col='valor']"))->toBe('Por persona')
        ->and($index->text("//tr[@data-key='cruces_en_derivadas']/td[@data-col='valor']"))->toBe('Sí');

    $again = Html::parse($this->actingAs($this->manager)->get('/comisiones/parametros/opciones/nueva')->assertOk()->getContent());

    expect($again->one("//select[@name='alcance_cumplimiento']/option[@selected]")->getAttribute('value'))->toBe('persona')
        ->and($again->one("//input[@name='cruces_en_derivadas']")->hasAttribute('checked'))->toBeTrue();

    // VAT is required with «incluye IVA» only when it is not a percentage.
    $this->actingAs($this->manager)->from('/comisiones/parametros/opciones/nueva')
        ->post('/comisiones/parametros/opciones', optionsVersionForm(['base_venta' => 'con_iva', 'iva_venta' => '200', 'vigente_desde' => '2026-12-01']))
        ->assertSessionHasErrors(['iva_venta']);
});
