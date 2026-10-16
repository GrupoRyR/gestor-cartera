<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
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

it('serves the dashboard at /cartera', function () {
    expect(route('cartera.tablero', absolute: false))->toBe('/cartera');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cartera')->assertRedirect('/login');
});

it('opens for the portfolio manager and the analyst', function (Role $role) {
    $this->actingAs(CarteraUsers::create($role))->get('/cartera')->assertOk();
})->with([Role::PortfolioManager, Role::PortfolioAnalyst]);

it('is closed to every other role and to users without one', function (?Role $role) {
    $this->actingAs(CarteraUsers::create($role))->get('/cartera')->assertForbidden();
})->with([
    'admin' => [Role::Admin],
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'treasury' => [Role::Treasury],
    'no role' => [null],
]);

it('asks for a first load when there is no portfolio yet', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertOk()
        ->assertSeeText('Aún no hay cartera cargada');
});

it('shows the total, the overdue amount and its share of the portfolio', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 2_000_000, notDue: 6_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente B', overdue: 1_000_000, notDue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente C', notDue: 500_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertOk()
        ->assertSeeText('Cartera total')
        ->assertSee(Money::format(10_500_000))
        ->assertSee(Money::format(3_000_000))
        ->assertSeeText('28,6 % de la cartera')
        ->assertSeeText('riesgo medio')
        ->assertSeeText('2 clientes');
});

it('draws the age of the overdue debt band by band', function () {
    $client = CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 4_000_000, daysOverdue: 45);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('Edades de la cartera vencida')
        ->assertSeeText('31 a 60 días')
        ->assertSeeText('100,0 %')
        ->assertSee('width: 100.00%', false);

    expect($client->overdue)->toBe(4_000_000.0);
});

it('lists the ten clients owing the most overdue money, the largest first', function () {
    foreach (range(1, 12) as $step) {
        CarteraWorld::client($this->fakes, name: sprintf('Deudor %02d', $step), overdue: $step * 1_000_000);
    }

    CarteraWorld::client($this->fakes, name: 'Cliente al día', notDue: 90_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeInOrder(['Deudor 12', 'Deudor 11', 'Deudor 10', 'Deudor 04', 'Deudor 03'])
        ->assertDontSeeText('Deudor 02')
        ->assertDontSeeText('Deudor 01')
        ->assertDontSeeText('Cliente al día');
});

it('says so when nobody is overdue', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente al día', notDue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('Sin cartera vencida')
        ->assertSeeText('No hay cartera vencida.');
});

it('shows the commitments due this week and counts what they promise', function () {
    $this->fakes->compromisos->add(
        Make::commitment(clientName: 'Cliente hoy', amount: 1_000_000, dueDate: CarbonImmutable::today()),
        Make::commitment(clientName: 'Cliente en tres días', amount: 2_000_000, dueDate: CarbonImmutable::today()->addDays(3)),
        Make::commitment(clientName: 'Cliente en diez días', amount: 4_000_000, dueDate: CarbonImmutable::today()->addDays(10)),
        Make::commitment(clientName: 'Cliente cumplido', amount: 8_000_000, dueDate: CarbonImmutable::today()->addDays(2), status: CommitmentStatus::Fulfilled),
    );
    CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('Cliente hoy')
        ->assertSeeText('Cliente en tres días')
        ->assertDontSeeText('Cliente en diez días')
        ->assertDontSeeText('Cliente cumplido')
        ->assertSee(Money::format(3_000_000).' prometidos');
});

it('counts the overdue clients nobody has managed lately', function () {
    $recent = CarbonImmutable::now()->subDays(2);
    $old = CarbonImmutable::now()->subDays(30);

    CarteraWorld::client($this->fakes, name: 'Nunca gestionado', overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Gestionado hace un mes', overdue: 1_000_000, lastActivityAt: $old);
    CarteraWorld::client($this->fakes, name: 'Gestionado hace dos días', overdue: 1_000_000, lastActivityAt: $recent);
    CarteraWorld::client($this->fakes, name: 'Al día y sin gestión', notDue: 1_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('Vencidos sin gestión')
        ->assertSeeText('2 clientes vencidos sin gestión en 7 días');
});

it('reads the days without management from the Cartera settings', function () {
    config(['cartera.sin_gestion_dias' => 1]);
    $twoDaysAgo = CarbonImmutable::now()->subDays(2);

    CarteraWorld::client($this->fakes, name: 'Gestionado hace dos días', overdue: 1_000_000, lastActivityAt: $twoDaysAgo);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('1 cliente vencido sin gestión en 1 días');
});

it('lists the latest log entries without the ones the system wrote', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente llamado', overdue: 1_000_000);
    $this->fakes->gestiones->add(
        Make::activity(clientName: 'Cliente llamado', type: ActivityType::Call, result: 'Promete pago el viernes'),
        Make::activity(clientName: 'Cliente automático', type: ActivityType::System, summary: 'Cartera en cero', result: 'Cambio de estado'),
    );

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeText('Cliente llamado')
        ->assertSeeText('Promete pago el viernes')
        ->assertDontSeeText('Cliente automático');
});

it('sets SGX against SDC only in the group view', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente SGX', company: CompanyCode::SGX, overdue: 1_000_000, notDue: 9_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente SDC', company: CompanyCode::SDC, overdue: 4_000_000, notDue: 6_000_000);
    $user = CarteraUsers::manager();

    $this->actingAs($user)->get('/cartera')->assertOk()->assertDontSeeText('SGX frente a SDC');

    $this->fakes->currentCompany->switchTo(null);

    $this->actingAs($user)
        ->get('/cartera')
        ->assertOk()
        ->assertSeeText('SGX frente a SDC')
        ->assertSeeText('10,0 %')
        ->assertSeeText('40,0 %')
        ->assertSeeText('Riesgo bajo')
        ->assertSeeText('Riesgo alto')
        ->assertSee(Money::format(10_000_000));
});

it('labels the company of each top debtor in the group view', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente SGX', company: CompanyCode::SGX, overdue: 1_000_000);
    CarteraWorld::client($this->fakes, name: 'Cliente SDC', company: CompanyCode::SDC, overdue: 2_000_000, status: ClientStatus::Overdue);
    $this->fakes->currentCompany->switchTo(null);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSeeInOrder(['Cliente SDC', 'SDC', 'Cliente SGX', 'SGX']);
});

it('adds the dashboard to the menu of the portfolio team', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera')
        ->assertSee('href="'.route('cartera.tablero').'"', false);
});
