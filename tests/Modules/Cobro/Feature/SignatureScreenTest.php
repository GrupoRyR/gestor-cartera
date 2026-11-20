<?php

use App\Models\User;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Signatures\SignaturePerson;
use App\Modules\Cobro\Infrastructure\Persistence\SignatureSetting;
use App\Modules\Cobro\Infrastructure\Signatures\SignatureStore;
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

/*
 * "Firma del correo" (/cobro/firma): who signs the Cobro mails of each
 * company (name, title, mobile and PBX extension), editable without code
 * when the person in charge of the cartera changes. The company data of
 * the signature (legal name, address, PBX, e-mail, web) is fixed.
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * A valid form for PUT /cobro/firma/{empresa}, with $overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function cobroSignatureForm(array $overrides = []): array
{
    return [
        'nombre' => '  Laura Gómez  ',
        'cargo' => 'Analista de Cartera',
        'movil' => '+57 310 555 0101',
        'extension' => '142',
        ...$overrides,
    ];
}

it('serves the editor under /cobro/firma', function () {
    expect(route('cobro.firma.edit', absolute: false))->toBe('/cobro/firma')
        ->and(route('cobro.firma.update', ['empresa' => 'SGX'], absolute: false))->toBe('/cobro/firma/SGX')
        ->and(route('cobro.firma.reset', ['empresa' => 'SGX'], absolute: false))->toBe('/cobro/firma/SGX');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cobro/firma')->assertRedirect('/login');
    $this->put('/cobro/firma/SGX', cobroSignatureForm())->assertRedirect('/login');
    $this->delete('/cobro/firma/SGX')->assertRedirect('/login');
});

it('forbids the editor to roles without cobro.manage', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/firma')->assertForbidden();
    $this->actingAs($user)->put('/cobro/firma/SGX', cobroSignatureForm())->assertForbidden();

    app(SignatureStore::class)->save(CompanyCode::SGX, new SignaturePerson('Laura Gómez', 'Analista de Cartera', '+573105550101', '142'), null);
    $this->actingAs($user)->delete('/cobro/firma/SGX')->assertForbidden();

    expect(SignatureSetting::query()->count())->toBe(1);
})->with(CobroUsers::notManagers());

it('signs with the default person of each company until someone saves another', function (CompanyCode $company) {
    $person = app(SignatureStore::class)->for($company);

    expect($person->isDefault)->toBeTrue()
        ->and($person->toArray())->toBe([
            'name' => 'María Paula Alvarez',
            'title' => 'Auxiliar de Cartera',
            'mobile' => '+3153654788',
            'extension' => '141',
        ]);

    $this->actingAs($this->analyst)->get('/cobro/firma?empresa='.$company->value)
        ->assertOk()
        ->assertSeeText('Firma del correo')
        ->assertSeeText('Datos por defecto')
        ->assertSee('value="María Paula Alvarez"', false)
        ->assertSee('value="Auxiliar de Cartera"', false)
        ->assertSee('value="+3153654788"', false)
        ->assertSee('value="141"', false)
        ->assertDontSeeText('Volver a los datos por defecto');
})->with([[CompanyCode::SGX], [CompanyCode::SDC]]);

it('saves the person of one company, trimmed, and audits what changed', function () {
    $this->actingAs($this->analyst)
        ->put('/cobro/firma/SGX', cobroSignatureForm())
        ->assertRedirect('/cobro/firma?empresa=SGX')
        ->assertSessionHas('status', 'Guardaste la firma de Segurex Latam.');

    $saved = SignatureSetting::query()->sole();
    $person = app(SignatureStore::class)->for(CompanyCode::SGX);

    expect($saved->company)->toBe(CompanyCode::SGX)
        ->and($saved->updated_by)->toBe($this->analyst->id)
        ->and($person->isDefault)->toBeFalse()
        ->and($person->toArray())->toBe(['name' => 'Laura Gómez', 'title' => 'Analista de Cartera', 'mobile' => '+57 310 555 0101', 'extension' => '142'])
        ->and(app(SignatureStore::class)->for(CompanyCode::SDC)->isDefault)->toBeTrue();

    $this->fakes->audit->assertRecorded('cobro.signature.updated', fn (AuditEntry $entry): bool => $entry->subjectType === 'cobro_firma'
        && $entry->changes['name'] === ['old' => 'María Paula Alvarez', 'new' => 'Laura Gómez']
        && $entry->changes['title'] === ['old' => 'Auxiliar de Cartera', 'new' => 'Analista de Cartera']
        && $entry->context === ['company' => 'SGX']);

    $this->actingAs($this->analyst)->get('/cobro/firma?empresa=SGX')
        ->assertSeeText('Editada por María Paula Rojas')
        ->assertSee('value="Laura Gómez"', false)
        ->assertSeeText('Volver a los datos por defecto');
});

it('audits only the fields that changed', function () {
    $this->actingAs($this->analyst)->put('/cobro/firma/SDC', cobroSignatureForm([
        'nombre' => 'María Paula Alvarez',
        'cargo' => 'Auxiliar de Cartera',
        'movil' => '+3153654788',
        'extension' => '150',
    ]))->assertSessionHasNoErrors();

    $this->fakes->audit->assertRecorded('cobro.signature.updated', fn (AuditEntry $entry): bool => $entry->changes === ['extension' => ['old' => '141', 'new' => '150']]
        && $entry->context === ['company' => 'SDC']);
});

it('keeps a person without a PBX extension', function () {
    $this->actingAs($this->analyst)->put('/cobro/firma/SGX', cobroSignatureForm(['extension' => '']))->assertSessionHasNoErrors();

    expect(app(SignatureStore::class)->for(CompanyCode::SGX)->extension)->toBeNull();
});

it('rejects a missing name or title, a mobile that is not a number and an extension with letters', function () {
    $this->actingAs($this->analyst)
        ->from('/cobro/firma?empresa=SGX')
        ->put('/cobro/firma/SGX', cobroSignatureForm(['nombre' => ' ', 'cargo' => '', 'movil' => 'llamar a cartera', 'extension' => '14a']))
        ->assertRedirect('/cobro/firma?empresa=SGX')
        ->assertSessionHasErrors([
            'nombre' => 'Escribe el nombre de quien firma.',
            'cargo' => 'Escribe el cargo de quien firma.',
            'movil' => 'Escribe un móvil válido, por ejemplo +57 315 365 4788.',
            'extension' => 'La extensión solo lleva números, hasta 6.',
        ]);

    expect(SignatureSetting::query()->count())->toBe(0);
});

it('answers 404 for a company the user may not choose', function () {
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $this->actingAs($this->analyst)->put('/cobro/firma/SDC', cobroSignatureForm())->assertNotFound();
    $this->actingAs($this->analyst)->delete('/cobro/firma/SDC')->assertNotFound();

    expect(SignatureSetting::query()->count())->toBe(0);
});

it('goes back to the default person and audits it', function () {
    app(SignatureStore::class)->save(CompanyCode::SGX, new SignaturePerson('Laura Gómez', 'Analista de Cartera', '+573105550101', '142'), $this->analyst->id);

    $this->actingAs($this->analyst)->get('/cobro/firma?empresa=SGX')
        ->assertSee('action="'.route('cobro.firma.reset', ['empresa' => 'SGX']).'"', false);

    $this->actingAs($this->analyst)->delete('/cobro/firma/SGX')
        ->assertRedirect('/cobro/firma?empresa=SGX')
        ->assertSessionHas('status', 'La firma de Segurex Latam volvió a los datos por defecto.');

    expect(SignatureSetting::query()->count())->toBe(0)
        ->and(app(SignatureStore::class)->for(CompanyCode::SGX)->name)->toBe('María Paula Alvarez');

    $this->fakes->audit->assertRecorded('cobro.signature.reset', fn (AuditEntry $entry): bool => $entry->changes['name'] === 'Laura Gómez'
        && $entry->context['company'] === 'SGX');
});

it('restores nothing and audits nothing when the company saved no person', function () {
    $this->actingAs($this->analyst)->delete('/cobro/firma/SGX')->assertRedirect('/cobro/firma?empresa=SGX');

    expect($this->fakes->audit->entries())->toBe([]);
});

it('adds the signature to the Cobro menu of the cartera roles, after the copies', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));
    $analystKeys = $keys($this->analyst);

    expect($analystKeys)->toContain('cobro.firma')
        ->and(array_search('cobro.firma', $analystKeys, true))->toBe(array_search('cobro.copias', $analystKeys, true) + 1)
        ->and($keys(CobroUsers::create(Role::SalesAdvisor)))->not->toContain('cobro.firma');
});
