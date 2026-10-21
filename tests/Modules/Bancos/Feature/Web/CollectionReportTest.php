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

describe('the month', function () {
    it('shows the month of the day as the analyst keeps it: weeks by weekday, with the business days and the totals', function () {
        bancosReported('2026-10-01', '1000.00');   // Thursday
        bancosReported('2026-10-12', '100.00');    // the holiday: goes to Tuesday the 13th
        bancosReported('2026-10-13', '50.00');

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');
        $month = $response->viewData('month');
        $grid = $month->grids[0];

        expect($month->month->toDateString())->toBe('2026-10-01')
            ->and($month->businessDays)->toBe(21)
            ->and($grid->weeks[2]->businessDays)->toBe(4)
            ->and($grid->weeks[2]->cells[1]->amount)->toBe(150.0)
            ->and($grid->total)->toBe(1150.0);

        $response->assertSee('Recaudo diario de octubre de 2026')
            ->assertSee('festivo se suma al siguiente día hábil')
            ->assertSeeInOrder(['Semana', 'Días', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Total recaudo'])
            ->assertSeeInOrder(['01 oct. – 02 oct.', '05 oct. – 09 oct.', '12 oct. – 16 oct.', '19 oct. – 23 oct.', '26 oct. – 30 oct.'])
            ->assertSeeInOrder(['12 oct. – 16 oct.', '4', 'Festivo', '150', 'Aún no llega'])
            ->assertSee('1.150');
    });

    it('adds a weekend to the next business day and links each day to its detail', function () {
        bancosReported('2026-10-03', '500.00');   // Saturday
        bancosReported('2026-10-05', '20.00');    // Monday

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');

        expect($response->viewData('month')->grids[0]->weeks[1]->cells[0]->amount)->toBe(520.0);
        $response->assertSee(route('bancos.recaudo', ['fecha' => '2026-10-05']));
    });

    it('counts every credit, also the applied and the ignored ones', function () {
        bancosReported('2026-10-14', '100.00');
        bancosReported('2026-10-14', '40.00', fields: ['status' => 'aplicado', 'receipt_number' => '9']);
        bancosReported('2026-10-14', '2.00', fields: ['status' => 'ignorado']);

        $grid = $this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('month')->grids[0];

        expect($grid->weeks[2]->cells[2]->amount)->toBe(142.0);
    });

    it('says the days that have not arrived are not here yet, not zero', function () {
        $grid = $this->actingAs($this->analyst)->get('/bancos/recaudo')->viewData('month')->grids[0];

        expect($grid->weeks[2]->cells[4]->state->name)->toBe('Pending')   // Friday the 16th: today
            ->and($grid->weeks[2]->cells[3]->state->name)->toBe('Loaded');
    });

    it('draws a table per company in the group view, and only the active one otherwise', function () {
        bancosReported('2026-10-05', '1000.00', company: CompanyCode::SGX);
        bancosReported('2026-10-05', '300.00', company: CompanyCode::SDC);

        $this->actingAs($this->analyst)->get('/bancos/recaudo')
            ->assertSee('Segurex Latam (SGX)')
            ->assertDontSee('Soluciones y Derivados (SDC)');

        $this->fakes->currentCompany->switchTo(null);

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo');
        $grids = $response->viewData('month')->grids;

        expect($grids)->toHaveCount(2)
            ->and($grids[0]->total)->toBe(1000.0)
            ->and($grids[1]->total)->toBe(300.0);

        $response->assertSeeInOrder(['Segurex Latam (SGX)', 'Soluciones y Derivados (SDC)']);
    });

    it('goes to the month before, and not to a month that has not started', function () {
        $this->actingAs($this->analyst)->get('/bancos/recaudo')
            ->assertSee(route('bancos.recaudo', ['fecha' => '2026-09-30']))
            ->assertSee('Mes anterior')
            ->assertDontSee('Mes siguiente');
    });

    it('follows the day asked for, with links to the months around it', function () {
        bancosReported('2026-09-15', '40.00');

        $response = $this->actingAs($this->analyst)->get('/bancos/recaudo?fecha=2026-09-15');
        $month = $response->viewData('month');

        expect($month->month->toDateString())->toBe('2026-09-01')
            ->and($month->businessDays)->toBe(22)
            ->and($month->grids[0]->total)->toBe(40.0);

        $response->assertSee('Recaudo diario de septiembre de 2026')
            ->assertSee(route('bancos.recaudo', ['fecha' => '2026-08-31']))
            ->assertSee(route('bancos.recaudo', ['fecha' => '2026-10-01']))
            ->assertSee('Mes siguiente');
    });
});

describe('navigation', function () {
    it('has the report in the menu', function () {
        $items = collect(app(NavRegistry::class)->visibleTo($this->analyst))->keyBy('key');

        expect($items->has('bancos.recaudo'))->toBeTrue()
            ->and($items['bancos.recaudo']->url())->toEndWith('/bancos/recaudo');
    });
});
