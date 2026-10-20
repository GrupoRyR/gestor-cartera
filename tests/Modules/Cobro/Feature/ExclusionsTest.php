<?php

use App\Models\User;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Exclusions\ExclusionList;
use App\Modules\Cobro\Infrastructure\Persistence\Exclusion;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroUsers;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);

    $this->tornillo = Make::clientDetail(Make::client(name: 'Ferretería El Tornillo SAS', overdue: 4_500_000, maxDaysOverdue: 40));
    $this->andinas = Make::clientDetail(Make::client(name: 'Distribuciones Andinas SAS', overdue: 1_200_000, maxDaysOverdue: 12));
    $this->alDia = Make::clientDetail(Make::client(name: 'Comercial Al Día SAS'));
    $this->sdc = Make::clientDetail(Make::client(company: CompanyCode::SDC, name: 'Químicos del Norte SAS', overdue: 800_000, maxDaysOverdue: 9));
    $this->fakes->cartera->addClient($this->tornillo)->addClient($this->andinas)->addClient($this->alDia)->addClient($this->sdc);

    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * An exclusion row for a client.
 *
 * @param  array<string, mixed>  $attributes
 */
function cobroExclusion(ClientDetail $client, array $attributes = []): Exclusion
{
    return Exclusion::query()->create(array_merge([
        'company' => $client->summary->company,
        'client_id' => $client->summary->id,
        'client_code' => $client->summary->code,
        'client_name' => $client->summary->name,
        'tax_id' => $client->summary->taxId,
        'reason' => 'Acuerdo de pago vigente',
    ], $attributes));
}

it('serves the list under /cobro/exclusiones', function () {
    expect(route('cobro.exclusiones.index', absolute: false))->toBe('/cobro/exclusiones')
        ->and(route('cobro.exclusiones.store', absolute: false))->toBe('/cobro/exclusiones')
        ->and(route('cobro.exclusiones.destroy', absolute: false))->toBe('/cobro/exclusiones/quitar');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cobro/exclusiones')->assertRedirect('/login');
});

it('opens the list for the cartera manager and analyst', function (Role $role) {
    $this->actingAs(CobroUsers::create($role))->get('/cobro/exclusiones')
        ->assertOk()
        ->assertSeeText('Lista de exclusión');
})->with(CobroUsers::managers());

it('forbids the list and its changes to every other role', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/exclusiones')->assertForbidden();
    $this->actingAs($user)->post('/cobro/exclusiones', ['clientes' => [$this->tornillo->summary->id], 'motivo' => 'X'])->assertForbidden();
    $this->actingAs($user)->post('/cobro/exclusiones/quitar', ['exclusiones' => [1]])->assertForbidden();

    expect(Exclusion::query()->count())->toBe(0);
})->with(CobroUsers::notManagers());

it('lists the overdue clients of the active company with a checkbox each, most overdue first', function () {
    $response = $this->actingAs($this->analyst)->get('/cobro/exclusiones')->assertOk();

    $response->assertSeeInOrder(['Ferretería El Tornillo SAS', 'Distribuciones Andinas SAS'])
        ->assertSee('name="clientes[]"', false)
        ->assertSee('value="'.$this->tornillo->summary->id.'"', false)
        ->assertDontSeeText('Comercial Al Día SAS')
        ->assertDontSeeText('Químicos del Norte SAS');
});

it('shows every client with ?todos=1 and searches by name', function () {
    $this->actingAs($this->analyst)->get('/cobro/exclusiones?todos=1')->assertOk()->assertSeeText('Comercial Al Día SAS');

    $this->actingAs($this->analyst)->get('/cobro/exclusiones?buscar=andinas')->assertOk()
        ->assertSeeText('Distribuciones Andinas SAS')
        ->assertDontSeeText('Ferretería El Tornillo SAS');
});

it('excludes the marked clients with the reason and expiry, and audits it', function () {
    $this->actingAs($this->analyst)
        ->from('/cobro/exclusiones')
        ->post('/cobro/exclusiones', [
            'clientes' => [$this->tornillo->summary->id, $this->andinas->summary->id],
            'motivo' => '  Acuerdo de pago vigente  ',
            'vence' => '2026-11-30',
        ])
        ->assertRedirect('/cobro/exclusiones')
        ->assertSessionHas('status', 'Excluiste 2 clientes del cobro.');

    $exclusion = Exclusion::query()->where('client_id', $this->tornillo->summary->id)->sole();

    expect(Exclusion::query()->count())->toBe(2)
        ->and($exclusion->company)->toBe(CompanyCode::SGX)
        ->and($exclusion->client_name)->toBe('Ferretería El Tornillo SAS')
        ->and($exclusion->client_code)->toBe($this->tornillo->summary->code)
        ->and($exclusion->reason)->toBe('Acuerdo de pago vigente')
        ->and($exclusion->expires_on?->format('Y-m-d'))->toBe('2026-11-30')
        ->and($exclusion->created_by)->toBe($this->analyst->id);

    $this->fakes->audit->assertRecorded('cobro.exclusion.created', fn (AuditEntry $entry): bool => $entry->subjectId === $exclusion->id
        && $entry->changes === ['reason' => 'Acuerdo de pago vigente', 'expires_on' => '2026-11-30']);
});

it('updates the reason and expiry of a client already excluded', function () {
    $exclusion = cobroExclusion($this->tornillo, ['expires_on' => '2026-10-31', 'created_by' => $this->analyst->id]);
    $manager = CobroUsers::create(Role::PortfolioManager, 'Miguel Ángel');

    $this->actingAs($manager)->post('/cobro/exclusiones', [
        'clientes' => [$this->tornillo->summary->id],
        'motivo' => 'Proceso jurídico',
    ])->assertSessionHas('status', 'Excluiste 1 cliente del cobro.');

    $exclusion->refresh();

    expect(Exclusion::query()->count())->toBe(1)
        ->and($exclusion->reason)->toBe('Proceso jurídico')
        ->and($exclusion->expires_on)->toBeNull()
        ->and($exclusion->created_by)->toBe($this->analyst->id);

    $this->fakes->audit->assertRecorded('cobro.exclusion.updated', fn (AuditEntry $entry): bool => $entry->changes === [
        'reason' => ['old' => 'Acuerdo de pago vigente', 'new' => 'Proceso jurídico'],
        'expires_on' => ['old' => '2026-10-31', 'new' => null],
    ]);
});

it('never excludes a client out of the user scope', function () {
    $this->actingAs($this->analyst)->post('/cobro/exclusiones', [
        'clientes' => [$this->sdc->summary->id, 999_999],
        'motivo' => 'Acuerdo',
    ])->assertSessionHas('error', 'Ninguno de los clientes marcados está a tu alcance.');

    expect(Exclusion::query()->count())->toBe(0);
    $this->fakes->audit->assertNothingRecorded();
});

it('validates the marked clients, the reason and the expiry', function (array $data, string $field) {
    $this->actingAs($this->analyst)->post('/cobro/exclusiones', $data)->assertSessionHasErrors($field);

    expect(Exclusion::query()->count())->toBe(0);
})->with([
    'no client' => [['motivo' => 'Acuerdo'], 'clientes'],
    'no reason' => [['clientes' => [1]], 'motivo'],
    'past expiry' => [['clientes' => [1], 'motivo' => 'Acuerdo', 'vence' => '2026-10-19'], 'vence'],
    'bad date' => [['clientes' => [1], 'motivo' => 'Acuerdo', 'vence' => '30/11/2026'], 'vence'],
]);

it('shows the excluded clients with their reason, expiry and author', function () {
    cobroExclusion($this->tornillo, ['expires_on' => '2026-11-30', 'created_by' => $this->analyst->id]);
    cobroExclusion($this->andinas, ['expires_on' => '2026-10-01', 'reason' => 'Pago confirmado']);
    cobroExclusion($this->sdc, ['reason' => 'Cliente de otra empresa']);

    $this->actingAs($this->analyst)->get('/cobro/exclusiones')->assertOk()
        ->assertSeeText('Excluido hasta el 30/11/2026')
        ->assertSee('name="exclusiones[]"', false)
        ->assertSeeText('Acuerdo de pago vigente')
        ->assertSeeText('Pago confirmado')
        ->assertSeeText('Vencida')
        ->assertSeeText('María Paula Rojas')
        ->assertDontSeeText('Cliente de otra empresa');
});

it('removes the marked exclusions of the user companies and audits it', function () {
    $tornillo = cobroExclusion($this->tornillo);
    $sdc = cobroExclusion($this->sdc);

    $this->actingAs($this->analyst)->post('/cobro/exclusiones/quitar', ['exclusiones' => [$tornillo->id, $sdc->id]])
        ->assertSessionHas('status', 'Quitaste 1 cliente de la lista de exclusión.');

    expect(Exclusion::query()->pluck('id')->all())->toBe([$sdc->id]);
    $this->fakes->audit->assertRecorded('cobro.exclusion.deleted', fn (AuditEntry $entry): bool => $entry->subjectId === $tornillo->id);
});

it('knows which clients are excluded on a day', function () {
    cobroExclusion($this->tornillo);
    cobroExclusion($this->andinas, ['expires_on' => '2026-10-20']);
    cobroExclusion($this->alDia, ['expires_on' => '2026-10-19']);
    cobroExclusion($this->sdc);

    $today = CarbonImmutable::parse('2026-10-20', 'America/Bogota');

    expect(array_keys(app(ExclusionList::class)->activeFor(CompanyCode::SGX, $today)))
        ->toEqualCanonicalizing([$this->tornillo->summary->id, $this->andinas->summary->id]);
});

it('adds Lista de exclusión to the Cobro menu of the cartera roles', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));

    expect($keys($this->analyst))->toContain('cobro.exclusiones')
        ->and($keys(CobroUsers::create(Role::SalesAdvisor)))->not->toContain('cobro.exclusiones');
});
