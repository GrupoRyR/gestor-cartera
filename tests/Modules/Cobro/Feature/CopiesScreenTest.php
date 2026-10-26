<?php

use App\Models\User;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Copies\CopySettings;
use App\Modules\Cobro\Infrastructure\Copies\CopyStore;
use App\Modules\Cobro\Infrastructure\Persistence\CopySetting;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Cobro\Support\CobroUsers;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * A valid form for PUT /cobro/copias/{empresa}, with $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cobroCopiesForm(array $overrides = []): array
{
    return [
        'fijos' => "Cartera@segurex.com\r\nmiguel.martinez@segurex.com, cartera@segurex.com",
        'asesor' => '1',
        'gerentes' => [
            ['grupo' => ' DISTRIBUCION ', 'correos' => 'jessika.quintero@segurex.com'],
            ['grupo' => '', 'correos' => ''],
            ['grupo' => 'CONS.  RESIDENCIAL', 'correos' => 'andrea.hincapie@segurex.com; otra.gerente@segurex.com'],
        ],
        ...$overrides,
    ];
}

it('serves the editor under /cobro/copias', function () {
    expect(route('cobro.copias.edit', absolute: false))->toBe('/cobro/copias')
        ->and(route('cobro.copias.update', ['empresa' => 'SGX'], absolute: false))->toBe('/cobro/copias/SGX')
        ->and(route('cobro.copias.reset', ['empresa' => 'SGX'], absolute: false))->toBe('/cobro/copias/SGX');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cobro/copias')->assertRedirect('/login');
    $this->put('/cobro/copias/SGX', cobroCopiesForm())->assertRedirect('/login');
    $this->delete('/cobro/copias/SGX')->assertRedirect('/login');
});

it('forbids the editor to roles without cobro.manage', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/copias')->assertForbidden();
    $this->actingAs($user)->put('/cobro/copias/SGX', cobroCopiesForm())->assertForbidden();

    app(CopyStore::class)->save(CompanyCode::SGX, new CopySettings(['cartera@segurex.com']), null);
    $this->actingAs($user)->delete('/cobro/copias/SGX')->assertForbidden();

    expect(CopySetting::query()->count())->toBe(1);
})->with(CobroUsers::notManagers());

it('shows the default copies of each company until someone saves them', function () {
    $this->actingAs($this->analyst)->get('/cobro/copias')
        ->assertOk()
        ->assertSeeText('Copias del cobro semanal y la circularización')
        ->assertSeeText('Usa las copias por defecto')
        ->assertSee('miguel.martinez@segurex.com', false)
        ->assertSee('value="DISTRIBUCION"', false)
        ->assertSee('value="CONS. RESIDENCIAL"', false)
        ->assertSee('value="jessika.quintero@segurex.com"', false)
        ->assertSeeText('Copiar al asesor del cliente')
        ->assertSeeText('«Grupo» del cliente en SAP')
        ->assertSee('name="gerentes[4][grupo]"', false)
        ->assertDontSee('name="gerentes[5][grupo]"', false)
        ->assertDontSeeText('Volver a las copias por defecto');

    $this->actingAs($this->analyst)->get('/cobro/copias?empresa=SDC')
        ->assertOk()
        ->assertSee('miguel.martinez@derivadoscol.com', false)
        ->assertSee('diana.mora@derivadoscol.com', false)
        ->assertSee('servicioalcliente@derivadoscol.com', false);
});

it('saves the copies of a company, ignoring blank rows, and audits the change', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/copias/SGX', cobroCopiesForm(['asesor' => null]))
        ->assertRedirect('/cobro/copias?empresa=SGX')
        ->assertSessionHas('status', 'Guardaste las copias de Segurex Latam.');

    $saved = CopySetting::query()->sole();
    $settings = app(CopyStore::class)->for(CompanyCode::SGX);

    expect($saved->company)->toBe(CompanyCode::SGX)
        ->and($saved->updated_by)->toBe($this->analyst->id)
        ->and($settings->isDefault)->toBeFalse()
        ->and($settings->fixed)->toBe(['Cartera@segurex.com', 'miguel.martinez@segurex.com'])
        ->and($settings->advisor)->toBeFalse()
        ->and($settings->managers)->toBe([
            ['group' => 'DISTRIBUCION', 'emails' => ['jessika.quintero@segurex.com']],
            ['group' => 'CONS. RESIDENCIAL', 'emails' => ['andrea.hincapie@segurex.com', 'otra.gerente@segurex.com']],
        ])
        ->and(app(CopyStore::class)->for(CompanyCode::SDC)->isDefault)->toBeTrue();

    $this->fakes->audit->assertRecorded('cobro.copies.updated', fn (AuditEntry $entry): bool => $entry->changes['advisor'] === ['old' => true, 'new' => false]
        && $entry->changes['fixed']['old'] === ['miguel.martinez@segurex.com']);

    $this->actingAs($this->analyst)->get('/cobro/copias?empresa=SGX')
        ->assertSeeText('Editadas por María Paula Rojas')
        ->assertSee('value="andrea.hincapie@segurex.com, otra.gerente@segurex.com"', false);
});

it('rejects invalid addresses and incomplete manager rows', function () {
    $this->actingAs($this->analyst)
        ->from('/cobro/copias?empresa=SGX')
        ->put('/cobro/copias/SGX', cobroCopiesForm([
            'fijos' => 'miguel.martinez@segurex.com, no-es-un-correo',
            'gerentes' => [
                ['grupo' => 'DISTRIBUCION', 'correos' => ''],
                ['grupo' => '', 'correos' => 'andrea.hincapie@segurex.com'],
                ['grupo' => 'INDUSTRIA', 'correos' => 'gerente@@segurex.com'],
            ],
        ]))
        ->assertRedirect('/cobro/copias?empresa=SGX')
        ->assertSessionHasErrors([
            'fijos' => 'Estos correos no son válidos: no-es-un-correo.',
            'gerentes.0.correos' => 'Escribe al menos un correo para este grupo.',
            'gerentes.1.grupo' => 'Escribe el grupo de SAP de estos correos.',
            'gerentes.2.correos' => 'Estos correos no son válidos: gerente@@segurex.com.',
        ]);

    expect(CopySetting::query()->count())->toBe(0);
});

it('rejects an address whose domain has no dot', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/copias/SGX', cobroCopiesForm([
            'fijos' => 'ana@segurex, miguel.martinez@segurex.com',
            'gerentes' => [['grupo' => 'DISTRIBUCION', 'correos' => 'jessika.quintero@segurex.com, pedro@localhost']],
        ]))
        ->assertSessionHasErrors([
            'fijos' => 'Estos correos no son válidos: ana@segurex.',
            'gerentes.0.correos' => 'Estos correos no son válidos: pedro@localhost.',
        ]);

    expect(CopySetting::query()->count())->toBe(0);
});

it('rejects copies outside the company domains, subdomains included', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/copias/SGX', cobroCopiesForm([
            'fijos' => 'miguel.martinez@segurex.com, Tesoreria@Cliente.example, diana.mora@DERIVADOSCOL.com',
            'gerentes' => [['grupo' => 'DISTRIBUCION', 'correos' => 'jessika.quintero@segurex.com, x@mail.segurex.com']],
        ]))
        ->assertSessionHasErrors([
            'fijos' => 'Solo se permiten copias a correos de segurex.com o derivadoscol.com: Tesoreria@Cliente.example.',
            'gerentes.0.correos' => 'Solo se permiten copias a correos de segurex.com o derivadoscol.com: x@mail.segurex.com.',
        ]);

    expect(CopySetting::query()->count())->toBe(0);
});

it('tells which domains the copies accept', function () {
    $this->actingAs($this->analyst)->get('/cobro/copias')
        ->assertOk()
        ->assertSeeText('Solo se aceptan correos de segurex.com o derivadoscol.com.');
});

it('answers 404 for a company the user may not choose', function () {
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $this->actingAs($this->analyst)->put('/cobro/copias/SDC', cobroCopiesForm())->assertNotFound();
    $this->actingAs($this->analyst)->delete('/cobro/copias/SDC')->assertNotFound();

    expect(CopySetting::query()->count())->toBe(0);
});

it('restores the default copies and audits it', function () {
    app(CopyStore::class)->save(CompanyCode::SGX, new CopySettings(fixed: ['cartera@segurex.com'], advisor: false), $this->analyst->id);

    $this->actingAs($this->analyst)->get('/cobro/copias?empresa=SGX')
        ->assertSeeText('Volver a las copias por defecto')
        ->assertSee('action="'.route('cobro.copias.reset', ['empresa' => 'SGX']).'"', false);

    $this->actingAs($this->analyst)->delete('/cobro/copias/SGX')
        ->assertRedirect('/cobro/copias?empresa=SGX')
        ->assertSessionHas('status', 'Las copias de Segurex Latam volvieron a las de por defecto.');

    $settings = app(CopyStore::class)->for(CompanyCode::SGX);

    expect(CopySetting::query()->count())->toBe(0)
        ->and($settings->isDefault)->toBeTrue()
        ->and($settings->fixed)->toBe(['miguel.martinez@segurex.com'])
        ->and($settings->advisor)->toBeTrue();

    $this->fakes->audit->assertRecorded('cobro.copies.reset', fn (AuditEntry $entry): bool => $entry->changes['fixed'] === ['cartera@segurex.com']
        && $entry->context['company'] === 'SGX');

    $this->actingAs($this->analyst)->get('/cobro/copias?empresa=SGX')
        ->assertSeeText('Usa las copias por defecto')
        ->assertDontSeeText('Volver a las copias por defecto');
});

it('restores nothing and audits nothing when the company saved no copies', function () {
    $this->actingAs($this->analyst)->delete('/cobro/copias/SGX')->assertRedirect('/cobro/copias?empresa=SGX');

    expect($this->fakes->audit->entries())->toBe([]);
});

it('adds the copies to the Cobro menu of the cartera roles, after the template', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));
    $analystKeys = $keys($this->analyst);

    expect($analystKeys)->toContain('cobro.copias')
        ->and(array_search('cobro.copias', $analystKeys, true))->toBe(array_search('cobro.plantillas', $analystKeys, true) + 1)
        ->and($keys(CobroUsers::create(Role::SalesAdvisor)))->not->toContain('cobro.copias');
});
