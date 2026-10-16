<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
});

it('serves the client list at /cartera/clientes', function () {
    expect(route('cartera.clientes.index', absolute: false))->toBe('/cartera/clientes');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cartera/clientes')->assertRedirect('/login');
});

it('opens for the portfolio manager and the analyst only', function (?Role $role, int $status) {
    $this->actingAs(CarteraUsers::create($role))->get('/cartera/clientes')->assertStatus($status);
})->with([
    'manager' => [Role::PortfolioManager, 200],
    'analyst' => [Role::PortfolioAnalyst, 200],
    'advisor' => [Role::SalesAdvisor, 403],
    'sales manager' => [Role::SalesManager, 403],
    'no role' => [null, 403],
]);

it('shows each client with its NIT, balances, days overdue, status and advisor', function () {
    CarteraWorld::client(
        $this->fakes,
        name: 'Ferretería El Tornillo SAS',
        overdue: 2_000_000,
        notDue: 1_000_000,
        daysOverdue: 38,
        status: ClientStatus::Overdue,
        advisor: Make::advisor(name: 'Carlos Pérez'),
        taxId: '900123456',
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes')
        ->assertOk()
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText('900123456')
        ->assertSee(Money::format(3_000_000))
        ->assertSee(Money::format(2_000_000))
        ->assertSeeText('38')
        ->assertSeeText('Vencida')
        ->assertSeeText('Carlos Pérez');
});

it('puts the largest overdue amount first', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente pequeño', overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente grande', overdue: 9_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente mediano', overdue: 4_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes')
        ->assertSeeInOrder(['Cliente grande', 'Cliente mediano', 'Cliente pequeño']);
});

it('sorts by the column the user picks', function () {
    CarteraWorld::client($this->fakes, name: 'Beta', overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Alfa', overdue: 9_000_000);
    CarteraWorld::client($this->fakes, name: 'Gamma', overdue: 4_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes?sort=name&direction=asc')
        ->assertSeeInOrder(['Alfa', 'Beta', 'Gamma'])
        ->assertSee('aria-sort="ascending"', false);
});

it('opens anyway when the sort in the address is not one it knows', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes?sort=password&direction=sideways')->assertOk()->assertSeeText('Cliente A');
});

it('searches by name or NIT', function () {
    CarteraWorld::client($this->fakes, name: 'Ferretería El Tornillo SAS', overdue: 1_000_000, taxId: '900111222');
    CarteraWorld::client($this->fakes, name: 'Droguería La Esperanza', overdue: 1_000_000, taxId: '800333444');
    $analyst = CarteraUsers::analyst();

    $this->actingAs($analyst)->get('/cartera/clientes?buscar=tornillo')
        ->assertSeeText('Ferretería El Tornillo SAS')->assertDontSeeText('Droguería La Esperanza');

    $this->actingAs($analyst)->get('/cartera/clientes?buscar=800333')
        ->assertSeeText('Droguería La Esperanza')->assertDontSeeText('Ferretería El Tornillo SAS');
});

it('filters by status', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente vencido', overdue: 1_000_000, status: ClientStatus::Overdue);
    CarteraWorld::client($this->fakes, name: 'Cliente en gestión', overdue: 1_000_000, status: ClientStatus::InProgress);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes?estado=en_gestion')
        ->assertSeeText('Cliente en gestión')
        ->assertDontSeeText('Cliente vencido');
});

it('filters by advisor', function () {
    $carlos = Make::advisor(name: 'Carlos Pérez');
    $laura = Make::advisor(name: 'Laura Gómez');
    $this->fakes->cartera->addAdvisor($carlos, $laura);
    CarteraWorld::client($this->fakes, name: 'Cliente de Carlos', overdue: 1_000_000, advisor: $carlos);
    CarteraWorld::client($this->fakes, name: 'Cliente de Laura', overdue: 1_000_000, advisor: $laura);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes?asesor='.$laura->code)
        ->assertSeeText('Cliente de Laura')
        ->assertDontSeeText('Cliente de Carlos');
});

it('offers the advisors of the companies in view as a filter', function () {
    $this->fakes->cartera->addAdvisor(Make::advisor(name: 'Carlos Pérez'), Make::advisor(company: CompanyCode::SDC, name: 'Asesor de SDC'));

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes')
        ->assertSeeText('Carlos Pérez')
        ->assertDontSeeText('Asesor de SDC');
});

it('can show only the clients that are overdue', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente vencido', overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente al día', notDue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes?vencidos=1')
        ->assertSeeText('Cliente vencido')
        ->assertDontSeeText('Cliente al día');
});

it('can show only the overdue clients nobody has managed lately', function () {
    CarteraWorld::client($this->fakes, name: 'Moroso ignorado', overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Moroso atendido', overdue: 1_000_000, lastActivityAt: CarbonImmutable::now()->subDay());
    CarteraWorld::client($this->fakes, name: 'Cliente corriente', notDue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes?sin_gestion=1')
        ->assertSeeText('Moroso ignorado')
        ->assertDontSeeText('Moroso atendido')
        ->assertDontSeeText('Cliente corriente');
});

it('pages the list and keeps the search in the page links', function () {
    config(['cartera.por_pagina' => 2]);

    foreach (['Uno', 'Dos', 'Tres'] as $step => $name) {
        CarteraWorld::client($this->fakes, name: "Tornillo {$name}", overdue: (3 - $step) * 1_000_000);
    }

    $response = $this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes?buscar=tornillo');

    $response->assertSeeText('Tornillo Uno')->assertSeeText('Tornillo Dos')->assertDontSeeText('Tornillo Tres')
        ->assertSee('buscar=tornillo&amp;page=2', false);

    $this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes?buscar=tornillo&page=2')->assertSeeText('Tornillo Tres');
});

it('names the company of each client only in the group view', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente SDC', company: CompanyCode::SDC, overdue: 1_000_000);
    $user = CarteraUsers::analyst();

    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
    $this->actingAs($user)->get('/cartera/clientes')->assertDontSee('data-company', false);

    $this->fakes->currentCompany->switchTo(null);
    $this->actingAs($user)->get('/cartera/clientes')->assertSee('data-company="SDC"', false);
});

it('explains an empty list, and what to do when filters caused it', function () {
    $analyst = CarteraUsers::analyst();

    $this->actingAs($analyst)->get('/cartera/clientes')->assertSeeText('Aún no hay clientes');

    CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 1_000_000);

    $this->actingAs($analyst)->get('/cartera/clientes?buscar=inexistente')
        ->assertSeeText('Ningún cliente coincide')
        ->assertSee('href="'.route('cartera.clientes.index').'"', false);
});

it('lists the client list in the menu', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/clientes')
        ->assertSee('href="'.route('cartera.clientes.index').'"', false);
});
