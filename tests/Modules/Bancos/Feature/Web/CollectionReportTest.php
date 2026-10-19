<?php

use App\Modules\Bancos\Database\Seeders\BancosPermissionSeeder;
use App\Modules\Bancos\Infrastructure\EloquentRecaudoProvider;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\RecaudoProvider;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Bancos\Support\BancosUsers;

// "Now" is Friday 2026-10-16 09:00 in Bogotá: the report opens on Thursday the 15th.
beforeEach(function () {
    $this->seed(BancosPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    // The report reads the real provider; the fakes only stand in for Core.
    $this->app->singleton(RecaudoProvider::class, EloquentRecaudoProvider::class);
    $this->analyst = BancosUsers::create(Role::PortfolioAnalyst);
});

/**
 * A stored credit.
 *
 * @param  array<string, mixed>  $fields
 */
function bancosReported(string $date, string $amount, Bank $bank = Bank::Bogota, CompanyCode $company = CompanyCode::SGX, array $fields = []): BankMovement
{
    return BankMovement::factory()->forCompany($company)->inBank($bank)->on($date)->worth($amount)->create($fields);
}

it('is served at /bancos/recaudo, and /bancos goes there', function () {
    expect(route('bancos.recaudo', absolute: false))->toBe('/bancos/recaudo');

    $this->actingAs($this->analyst)->get('/bancos')->assertRedirect('/bancos/recaudo');
});

describe('access', function () {
    it('sends a guest to the login', function () {
        $this->get('/bancos/recaudo')->assertRedirect('/login');
    });

    it('lets the portfolio team and Treasury read it', function (Role $role) {
        $this->actingAs(BancosUsers::create($role))->get('/bancos/recaudo')->assertOk();
    })->with(BancosUsers::viewers());

    it('keeps everyone else out', function (?Role $role) {
        $this->actingAs(BancosUsers::create($role))->get('/bancos/recaudo')->assertForbidden();
    })->with(BancosUsers::outsiders());
});

describe('the day', function () {
    it('opens on yesterday: the movements arrive the day after', function () {
        bancosReported('2026-10-15', '1250000.00');
        bancosReported('2026-10-16', '999.00');

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');

        expect($response->viewData('date')->toDateString())->toBe('2026-10-15')
            ->and($response->viewData('day')->total)->toBe(1_250_000.0);

        $response->assertSee('jueves 15 de octubre')->assertSee('1.250.000');
    });

    it('adds the credits of every bank and shows each bank, even the ones with nothing', function () {
        bancosReported('2026-10-15', '1000000.00', Bank::Bogota);
        bancosReported('2026-10-15', '500000.50', Bank::Bancolombia);

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');

        $response->assertSeeInOrder(['Banco de Bogotá', 'Davivienda', 'Bancolombia', 'Miami'])
            ->assertSee('1.500.000,50')
            ->assertSee('Sin créditos');

        $day = $response->viewData('day');

        expect($day->bankTotal(Bank::Bogota))->toBe(1_000_000.0)
            ->and($day->bankTotal(Bank::Davivienda))->toBe(0.0)
            ->and($day->bankShare(Bank::Bogota))->toBe(66.7);
    });

    it('asks for another day', function () {
        bancosReported('2026-10-08', '777000.00');
        bancosReported('2026-10-15', '1.00');

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo?fecha=2026-10-08');

        expect($response->viewData('day')->total)->toBe(777_000.0);
        $response->assertSee('jueves 8 de octubre')->assertSee('777.000');
    });

    it('says there is nothing for a day with no credits, and why today is empty', function () {
        $this->actingAs($this->analyst)->get('/bancos/recaudo')
            ->assertSee('No hay créditos cargados para el 15 de octubre')
            ->assertSee('Si el extracto de ese día ya llegó');

        $this->actingAs($this->analyst)->get('/bancos/recaudo?fecha=2026-10-16')
            ->assertSee('llegan mañana');
    });

    it('does not accept a day after today or a date that is not a date', function (string $fecha) {
        $this->actingAs($this->analyst)->get('/bancos/recaudo?fecha='.$fecha)->assertSessionHasErrors('fecha');
    })->with(['2026-10-17', '15/10/2026', 'ayer', '1999-01-01']);

    it('counts the credits of the day that still wait for their receipt', function () {
        bancosReported('2026-10-15', '1.00');
        bancosReported('2026-10-15', '2.00');
        bancosReported('2026-10-15', '4.00', fields: ['status' => 'aplicado', 'receipt_number' => '9']);
        bancosReported('2026-10-15', '8.00', fields: ['status' => 'ignorado']);

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');

        expect($response->viewData('pending'))->toBe(2);
    });

    it('warns about credits in another currency, which do not add up', function () {
        bancosReported('2026-10-15', '1000.00');
        bancosReported('2026-10-15', '5000.00', Bank::Miami, fields: ['currency' => 'USD']);

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');

        $response->assertSee('Hay créditos que no suman en pesos');
        expect($response->viewData('day')->total)->toBe(1000.0)
            ->and($response->viewData('otherCurrency'))->toBe(1);
    });

    it('does not warn when everything is in pesos', function () {
        bancosReported('2026-10-15', '1000.00');

        $this->actingAs($this->analyst)->get('/bancos/recaudo')->assertDontSee('Hay créditos que no suman en pesos');
    });
});

describe('companies', function () {
    beforeEach(function () {
        bancosReported('2026-10-15', '1000.00', company: CompanyCode::SGX);
        bancosReported('2026-10-15', '300.00', company: CompanyCode::SDC);
    });

    it('reports the active company only', function () {
        $day = $this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('day');

        expect($day->total)->toBe(1000.0)->and($day->companies)->toBe([CompanyCode::SGX]);

        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

        expect($this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('day')->total)->toBe(300.0);
    });

    it('puts the companies side by side in the group view, with a total each', function () {
        $this->fakes->currentCompany->switchTo(null);

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');
        $day = $response->viewData('day');

        expect($day->total)->toBe(1300.0)
            ->and($day->companyTotals)->toBe(['SGX' => 1000.0, 'SDC' => 300.0]);

        $response->assertSee('Segurex Latam')->assertSee('Soluciones y Derivados');
    });

    it('never adds a company the user cannot choose', function () {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

        expect($this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('day')->total)->toBe(1000.0);
    });
});

describe('the week', function () {
    it('accumulates the week of the day, Monday to Sunday', function () {
        bancosReported('2026-10-12', '100.00');   // Monday
        bancosReported('2026-10-14', '50.00');    // Wednesday
        bancosReported('2026-10-15', '25.00');    // Thursday
        bancosReported('2026-10-11', '999.00');   // the Sunday before

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');
        $week = $response->viewData('week');

        expect($week->total)->toBe(175.0)
            ->and($week->days[0]->date->toDateString())->toBe('2026-10-12')
            ->and($week->days[3]->cumulative)->toBe(175.0)
            ->and($week->days[4]->loaded)->toBeFalse();   // Friday: today, not here yet

        $response->assertSee('Semana del 12 de octubre al 18 de octubre')->assertSee('Aún no llega');
    });

    it('compares with the week before over the same weekdays', function () {
        bancosReported('2026-10-12', '300.00');
        bancosReported('2026-10-05', '100.00');
        bancosReported('2026-10-08', '100.00');
        bancosReported('2026-10-09', '9000.00');   // Friday of the week before: not counted yet

        $week = $this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('week');

        expect($week->previousTotal)->toBe(200.0)->and($week->trend())->toBe('up');
    });

    it('follows the day asked for', function () {
        bancosReported('2026-10-06', '40.00');

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo?fecha=2026-10-08');
        $week = $response->viewData('week');

        expect($week->start->toDateString())->toBe('2026-10-05')
            ->and($week->total)->toBe(40.0);
    });

    it('offers the same numbers as a table', function () {
        bancosReported('2026-10-12', '100.00');

        $this->actingAs($this->analyst)->get('/bancos/recaudo')->assertSee('Ver los mismos datos en una tabla');
    });
});

describe('navigation', function () {
    it('has the report in the menu', function () {
        $items = collect(app(NavRegistry::class)->visibleTo($this->analyst))->keyBy('key');

        expect($items->has('bancos.recaudo'))->toBeTrue()
            ->and($items['bancos.recaudo']->url())->toEndWith('/bancos/recaudo');
    });
});
