<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CoreUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
});

it('lists only the clients of the advisor, the one with most overdue first', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'))->assertOk();

    expect(Page::clientNames($response))->toBe(['Agro Arango', 'Ferretería Franco']);
});

it('shows each advisor their own clients and nobody else’s', function () {
    expect(Page::clientNames($this->actingAs($this->world->bruno)->get(route('asesores.clientes.index'))))->toBe(['Bodega Bedoya']);
});

it('sorts by the column and direction of the URL', function (string $sort, string $direction, array $names) {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['sort' => $sort, 'direction' => $direction]));

    expect(Page::clientNames($response))->toBe($names);
})->with([
    'name ascending' => ['name', 'asc', ['Agro Arango', 'Ferretería Franco']],
    'name descending' => ['name', 'desc', ['Ferretería Franco', 'Agro Arango']],
    'balance ascending' => ['balance', 'asc', ['Ferretería Franco', 'Agro Arango']],
    'balance descending' => ['balance', 'desc', ['Agro Arango', 'Ferretería Franco']],
]);

it('keeps the default order when the sort is not one it knows', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['sort' => 'password', 'direction' => 'sideways']))->assertOk();

    expect(Page::clientNames($response))->toBe(['Agro Arango', 'Ferretería Franco']);
});

it('searches by name, SAP code and NIT', function (string $term) {
    $term = $term === 'code' ? $this->world->clients['agro']->code : ($term === 'nit' ? $this->world->clients['agro']->tax_id : $term);

    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['buscar' => $term]));

    expect(Page::clientNames($response))->toBe(['Agro Arango']);
})->with(['name' => ['agro'], 'code' => ['code'], 'nit' => ['nit']]);

it('filters by status', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['estado' => 'por_vencer']));

    expect(Page::clientNames($response))->toBe(['Ferretería Franco']);
});

it('can show only the clients with overdue balance', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['vencidos' => '1']));

    expect(Page::clientNames($response))->toBe(['Agro Arango']);
});

it('keeps the clients of another advisor out even when the URL asks for them', function () {
    foreach ([['asesor' => 'SGX-20'], ['buscar' => 'Bodega'], ['asesor' => 'SGX-20', 'buscar' => 'Bodega Bedoya'], ['asesor' => 'SDC-12']] as $query) {
        $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', $query))->assertOk();

        expect(Page::clientNames($response))->toBe([], json_encode($query));
        $response->assertSeeText('Ningún cliente coincide')->assertDontSeeText('Bodega Bedoya');
    }
});

it('never lists the clients of the other company with the same SAP code', function () {
    // Equipos Echeverri is SDC-12 and Ana is SGX-12.
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['buscar' => 'Equipos']));

    expect(Page::clientNames($response))->toBe([]);
});

it('shows a sales manager the clients of the team, with their advisor', function () {
    $response = $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route('asesores.clientes.index'))
        ->assertOk();

    expect(Page::clientNames($response))->toBe(['Granos Giraldo', 'Comercial Castaño', 'Jardines Jaramillo'])
        ->and(Page::column($response, 3))->toBe(['Laura Vélez', 'Felipe Ospina', 'Juliana Restrepo']);
});

it('has no advisor column for who only has one advisor to look at', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'));

    expect(Page::html($response)->count('//table//th[normalize-space(.)="Asesor"]'))->toBe(0);
});

it('shows the figures of each client and flags the overdue ones nobody has managed', function () {
    $ana = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'));
    $bruno = $this->actingAs($this->world->bruno)->get(route('asesores.clientes.index'));

    expect(Page::column($ana, 3))->toBe([Money::format(5_000_000), '—'])
        ->and(Page::column($ana, 4))->toBe(['100 días', '—'])
        ->and(Page::column($ana, 5))->toBe([Money::format(7_000_000), Money::format(1_500_000)])
        ->and(Page::column($ana, 6))->toBe(['14/10/2026', '—'])
        ->and(Page::column($bruno, 6))->toBe(['Sin gestión']);
});

it('sums up the clients, balance and overdue of the same filter', function () {
    $all = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'));
    $overdue = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['vencidos' => '1']));

    $all->assertSeeText('2 clientes · saldo '.Money::format(8_500_000).' · vencido '.Money::format(5_000_000));
    $overdue->assertSeeText('1 cliente · saldo '.Money::format(7_000_000).' · vencido '.Money::format(5_000_000));
});

it('pages the list 25 clients at a time', function () {
    foreach (range(1, 30) as $number) {
        $this->world->client(sprintf('Cliente %02d', $number), CompanyCode::SGX, 12, [['100000.00', 1]]);
    }

    $first = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['sort' => 'name', 'direction' => 'asc']));
    $second = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['sort' => 'name', 'direction' => 'asc', 'page' => 2]));

    expect(Page::clientNames($first))->toHaveCount(25)
        ->and(Page::clientNames($second))->toHaveCount(7);
});

it('says what to do when there is nothing to list', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->get(route('asesores.clientes.index'))
        ->assertOk()
        ->assertSeeText('Aún no tienes clientes con saldo');
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.clientes.index'))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.clientes.index'))->assertForbidden();
});
