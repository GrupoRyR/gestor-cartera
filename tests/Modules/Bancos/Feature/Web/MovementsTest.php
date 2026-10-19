<?php

use App\Modules\Bancos\Database\Seeders\BancosPermissionSeeder;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Bancos\Support\BancosUsers;

beforeEach(function () {
    $this->seed(BancosPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * A stored credit of Bogotá for SGX; the fields can be overridden.
 *
 * @param  array<string, mixed>  $fields
 */
function bancosStored(array $fields = []): BankMovement
{
    return BankMovement::factory()->create(array_merge(['bank' => Bank::Bogota, 'company' => CompanyCode::SGX], $fields));
}

/**
 * References listed for a query string, newest first.
 *
 * @return list<string>
 */
function bancosListed(string $query): array
{
    $movements = test()->actingAs(test()->analyst)->get('/bancos/movimientos/bogota?'.$query)->viewData('movements');

    return $movements->getCollection()->pluck('reference')->all();
}

it('is served under /bancos/movimientos', function () {
    expect(route('bancos.movimientos.index', absolute: false))->toBe('/bancos/movimientos')
        ->and(route('bancos.movimientos.banco', 'bogota', absolute: false))->toBe('/bancos/movimientos/bogota')
        ->and(route('bancos.movimientos.show', 7, absolute: false))->toBe('/bancos/movimiento/7')
        ->and(route('bancos.movimientos.update', 7, absolute: false))->toBe('/bancos/movimiento/7');
});

describe('access', function () {
    it('sends a guest to the login', function () {
        $this->get('/bancos/movimientos/bogota')->assertRedirect('/login');
        $this->get('/bancos/movimiento/1')->assertRedirect('/login');
        $this->patch('/bancos/movimiento/1')->assertRedirect('/login');
    });

    it('lets the portfolio team and Treasury see the movements', function (Role $role) {
        $this->actingAs(BancosUsers::create($role))->get('/bancos/movimientos/bogota')->assertOk();
    })->with(BancosUsers::viewers());

    it('keeps everyone else out', function (?Role $role) {
        $user = BancosUsers::create($role);
        $movement = bancosStored();

        $this->actingAs($user)->get('/bancos/movimientos/bogota')->assertForbidden();
        $this->actingAs($user)->get('/bancos/movimiento/'.$movement->id)->assertForbidden();
        $this->actingAs($user)->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'ignorado'])->assertForbidden();
    })->with(BancosUsers::outsiders());

    it('opens the first bank from /bancos/movimientos', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos')->assertRedirect('/bancos/movimientos/bogota');
    });

    it('answers 404 for a bank that does not exist', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/bbva')->assertNotFound();
    });
});

describe('the list of a bank', function () {
    it('shows the credits of that bank only', function () {
        bancosStored(['description' => 'DE BOGOTA']);
        bancosStored(['bank' => Bank::Davivienda, 'description' => 'DE DAVIVIENDA']);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/bogota')
            ->assertSee('DE BOGOTA')
            ->assertDontSee('DE DAVIVIENDA');
    });

    it('shows the companies the user has in view, SGX by default and both in the group view', function () {
        bancosStored(['description' => 'COBRO DE SGX']);
        bancosStored(['company' => CompanyCode::SDC, 'description' => 'COBRO DE SDC']);
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        $this->actingAs($user)->get('/bancos/movimientos/bogota')->assertSee('COBRO DE SGX')->assertDontSee('COBRO DE SDC');

        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
        $this->actingAs($user)->get('/bancos/movimientos/bogota')->assertSee('COBRO DE SDC')->assertDontSee('COBRO DE SGX');

        $this->fakes->currentCompany->switchTo(null);
        $this->actingAs($user)->get('/bancos/movimientos/bogota')->assertSee('COBRO DE SGX')->assertSee('COBRO DE SDC');
    });

    it('never shows a company the user cannot choose', function () {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
        bancosStored(['company' => CompanyCode::SDC, 'description' => 'COBRO DE SDC']);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/bogota')->assertDontSee('COBRO DE SDC');
    });

    it('has a tab per bank with the credits still pending', function () {
        bancosStored();
        bancosStored();
        bancosStored(['status' => MovementStatus::Applied, 'receipt_number' => '1']);
        bancosStored(['bank' => Bank::Miami]);

        $response = $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/bogota');

        $response->assertSeeInOrder(['Banco de Bogotá', 'Davivienda', 'Bancolombia', 'Miami']);
        $response->assertSee('aria-current="page"', false);

        $counts = collect($response->viewData('pending'))->all();
        expect($counts)->toBe(['bogota' => 2, 'miami' => 1]);
    });

    it('adds up what is listed, one total per currency', function () {
        bancosStored(['amount' => '1000000.00']);
        bancosStored(['amount' => '250000.50', 'status' => MovementStatus::Ignored]);
        bancosStored(['amount' => '100.00', 'currency' => 'USD']);

        $totals = $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/bogota')->viewData('totals');

        expect($totals)->toBe([
            ['currency' => 'COP', 'total' => '1250000.50', 'count' => 2, 'pending' => 1],
            ['currency' => 'USD', 'total' => '100.00', 'count' => 1, 'pending' => 1],
        ]);
    });

    it('paginates 25 at a time, newest first', function () {
        foreach (range(1, 30) as $day) {
            bancosStored(['movement_date' => CarbonImmutable::parse('2026-09-01')->addDays($day - 1), 'reference' => sprintf('REF-%02d', $day)]);
        }
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        $first = $this->actingAs($user)->get('/bancos/movimientos/bogota');
        $second = $this->actingAs($user)->get('/bancos/movimientos/bogota?page=2');

        expect($first->viewData('movements')->count())->toBe(25)
            ->and($second->viewData('movements')->count())->toBe(5);

        $first->assertSee('REF-30')->assertDontSee('REF-01');
        $second->assertSee('REF-01')->assertDontSee('REF-30');
    });

    it('invites the user to load a statement when the bank has nothing', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimientos/miami')
            ->assertSee('Aún no hay movimientos de Miami')
            ->assertSee('Ver las cargas');
    });

    it('shows the company column only when more than one company is in view', function () {
        bancosStored();
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        expect($this->actingAs($user)->get('/bancos/movimientos/bogota')->viewData('showCompany'))->toBeFalse();

        $this->fakes->currentCompany->switchTo(null);

        expect($this->actingAs($user)->get('/bancos/movimientos/bogota')->viewData('showCompany'))->toBeTrue();
    });
});

describe('filters', function () {
    beforeEach(function () {
        bancosStored(['movement_date' => '2026-10-01', 'amount' => '100000.00', 'description' => 'PAGO FERRETERIA', 'reference' => 'AAA', 'status' => MovementStatus::Pending]);
        bancosStored(['movement_date' => '2026-10-10', 'amount' => '500000.00', 'description' => 'PAGO MODA', 'reference' => 'BBB', 'status' => MovementStatus::Applied, 'receipt_number' => '77']);
        bancosStored(['movement_date' => '2026-10-15', 'amount' => '900000.00', 'description' => 'PAGO LOGISTICA', 'reference' => 'CCC', 'status' => MovementStatus::Ignored]);
        $this->analyst = BancosUsers::create(Role::PortfolioAnalyst);
    });

    it('narrows by date, both ends included', function () {
        expect(bancosListed('desde=2026-10-10'))->toBe(['CCC', 'BBB'])
            ->and(bancosListed('hasta=2026-10-10'))->toBe(['BBB', 'AAA'])
            ->and(bancosListed('desde=2026-10-10&hasta=2026-10-10'))->toBe(['BBB']);
    });

    it('narrows by amount, both ends included', function () {
        expect(bancosListed('valor_min=500000'))->toBe(['CCC', 'BBB'])
            ->and(bancosListed('valor_max=500000'))->toBe(['BBB', 'AAA'])
            ->and(bancosListed('valor_min=500000&valor_max=500000'))->toBe(['BBB']);
    });

    it('narrows by status', function (string $status, array $expected) {
        expect(bancosListed('estado='.$status))->toBe($expected);
    })->with([
        'pending' => ['pendiente', ['AAA']],
        'applied' => ['aplicado', ['BBB']],
        'ignored' => ['ignorado', ['CCC']],
    ]);

    it('searches the description and the reference', function () {
        expect(bancosListed('q=moda'))->toBe(['BBB'])
            ->and(bancosListed('q=CCC'))->toBe(['CCC'])
            ->and(bancosListed('q=PAGO'))->toBe(['CCC', 'BBB', 'AAA']);
    });

    it('does not treat % or _ in the search as wildcards', function () {
        expect(bancosListed('q=%25'))->toBe(['CCC', 'BBB', 'AAA']);   // only blanks remain: matches nothing special
    });

    it('combines the filters', function () {
        expect(bancosListed('desde=2026-10-02&estado=ignorado&valor_min=800000&q=logistica'))->toBe(['CCC'])
            ->and(bancosListed('desde=2026-10-02&estado=pendiente'))->toBe([]);
    });

    it('sorts by the column asked for', function () {
        expect(bancosListed('sort=amount&direction=asc'))->toBe(['AAA', 'BBB', 'CCC'])
            ->and(bancosListed('sort=amount&direction=desc'))->toBe(['CCC', 'BBB', 'AAA'])
            ->and(bancosListed('sort=date&direction=asc'))->toBe(['AAA', 'BBB', 'CCC']);
    });

    it('refuses a column it cannot sort by', function () {
        $this->actingAs($this->analyst)->get('/bancos/movimientos/bogota?sort=password')->assertSessionHasErrors('sort');
    });

    it('refuses bad filter values instead of ignoring them', function (string $query, string $field) {
        $this->actingAs($this->analyst)->get('/bancos/movimientos/bogota?'.$query)->assertSessionHasErrors($field);
    })->with([
        'date not in the format' => ['desde=01/10/2026', 'desde'],
        'end before start' => ['desde=2026-10-10&hasta=2026-10-01', 'hasta'],
        'amount not a number' => ['valor_min=mucho', 'valor_min'],
        'max below min' => ['valor_min=10&valor_max=5', 'valor_max'],
        'status that does not exist' => ['estado=perdido', 'estado'],
    ]);

    it('says no movement matches, and offers to clear the filters', function () {
        $this->actingAs($this->analyst)->get('/bancos/movimientos/bogota?q=nadie')
            ->assertSee('Ningún movimiento coincide con los filtros')
            ->assertSee('Quitar filtros');
    });
});

describe('one movement', function () {
    it('shows its data and the load it came from', function () {
        $movement = bancosStored(['description' => 'TRANSFERENCIA ACH DE MODA SAS', 'reference' => 'ACH-9', 'account' => '000-1', 'amount' => '1250000.50']);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimiento/'.$movement->id)
            ->assertOk()
            ->assertSee('TRANSFERENCIA ACH DE MODA SAS')
            ->assertSee('ACH-9')
            ->assertSee('000-1')
            ->assertSee('1.250.000')
            ->assertSee(route('bancos.cargas.show', $movement->load_id), false);
    });

    it('lets the analyst fill the receipt, and Treasury only read', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimiento/'.$movement->id)
            ->assertSee('Número de recibo de caja')
            ->assertSee('name="recibo"', false);

        $this->actingAs(BancosUsers::create(Role::Treasury))->get('/bancos/movimiento/'.$movement->id)
            ->assertOk()
            ->assertDontSee('name="recibo"', false)
            ->assertSee('registrar el recibo es tarea de cartera');
    });

    it('answers 404 for a movement of a company the user cannot choose, to read or to change', function () {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
        $movement = bancosStored(['company' => CompanyCode::SDC]);
        $analyst = BancosUsers::create(Role::PortfolioAnalyst);

        $this->actingAs($analyst)->get('/bancos/movimiento/'.$movement->id)->assertNotFound();
        $this->actingAs($analyst)->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'ignorado'])->assertNotFound();

        expect($movement->fresh()->status)->toBe(MovementStatus::Pending);
    });

    it('answers 404 for a movement that does not exist', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/movimiento/9999')->assertNotFound();
    });
});

describe('registering the receipt', function () {
    it('applies a movement with its cash receipt number, who and when', function () {
        $movement = bancosStored();
        $analyst = BancosUsers::create(Role::PortfolioAnalyst);

        $this->actingAs($analyst)->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => '4521'])
            ->assertRedirect('/bancos/movimientos/bogota')
            ->assertSessionHas('status');

        $fresh = $movement->fresh();

        expect($fresh->status)->toBe(MovementStatus::Applied)
            ->and($fresh->receipt_number)->toBe('4521')
            ->and($fresh->applied_by)->toBe($analyst->id)
            ->and($fresh->applied_at?->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    it('accepts several receipts for one payment', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => ' 4521, 4522 ']);

        expect($movement->fresh()->receipt_number)->toBe('4521, 4522');
    });

    it('does not apply without a receipt number', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => ''])
            ->assertSessionHasErrors('recibo');

        expect($movement->fresh()->status)->toBe(MovementStatus::Pending)
            ->and(session('errors')->first('recibo'))->toContain('recibo de caja');
    });

    it('rejects receipts with strange characters or too long', function (string $receipt) {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => $receipt])
            ->assertSessionHasErrors('recibo');

        expect($movement->fresh()->status)->toBe(MovementStatus::Pending);
    })->with(['<script>', '45;21', '-4521', str_repeat('1', 61)]);

    it('rejects a status that does not exist', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.bancosStored()->id, ['estado' => 'perdido'])->assertSessionHasErrors('estado');
    });

    it('ignores a movement, and forgets any receipt it had', function () {
        $movement = bancosStored(['status' => MovementStatus::Applied, 'receipt_number' => '4521', 'applied_by' => BancosUsers::create(Role::PortfolioAnalyst)->id, 'applied_at' => now()]);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'ignorado', 'recibo' => '9999']);

        $fresh = $movement->fresh();

        expect($fresh->status)->toBe(MovementStatus::Ignored)
            ->and($fresh->receipt_number)->toBeNull()
            ->and($fresh->applied_by)->toBeNull()
            ->and($fresh->applied_at)->toBeNull();
    });

    it('puts an applied movement back to pending', function () {
        $movement = bancosStored(['status' => MovementStatus::Applied, 'receipt_number' => '4521']);

        $this->actingAs(BancosUsers::create(Role::PortfolioManager))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'pendiente']);

        expect($movement->fresh()->status)->toBe(MovementStatus::Pending)
            ->and($movement->fresh()->receipt_number)->toBeNull();
    });

    it('does not let Treasury apply payments', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::Treasury))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => '1'])->assertForbidden();

        expect($movement->fresh()->status)->toBe(MovementStatus::Pending);
    });

    it('audits what changed and who is not needed twice: the old and new status and receipt', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'aplicado', 'recibo' => '4521']);

        $this->fakes->audit->assertRecorded('bancos.movimiento.updated', fn ($entry) => $entry->subjectId === $movement->id
            && $entry->changes === [
                'status' => ['old' => 'pendiente', 'new' => 'aplicado'],
                'receipt_number' => ['old' => null, 'new' => '4521'],
            ]);
    });

    it('audits nothing when nothing changed', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'pendiente'])->assertRedirect();

        $this->fakes->audit->assertNothingRecorded();
    });

    it('goes back to the list the user came from, with its filters', function () {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))
            ->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'ignorado', 'volver' => url('/bancos/movimientos/bogota?estado=pendiente&page=2')])
            ->assertRedirect('/bancos/movimientos/bogota?estado=pendiente&page=2');
    });

    it('never goes back to another address', function (string $volver) {
        $movement = bancosStored();

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))
            ->patch('/bancos/movimiento/'.$movement->id, ['estado' => 'ignorado', 'volver' => $volver])
            ->assertRedirect(route('bancos.movimientos.banco', 'bogota'));
    })->with([
        'other host' => ['https://evil.example/bancos/movimientos/bogota'],
        'protocol relative' => ['//evil.example/bancos/movimientos/bogota'],
        'other path' => ['/admin'],
        'script' => ['javascript:alert(1)'],
    ]);

    it('offers the list the user came from as the way back', function () {
        $movement = bancosStored();

        $response = $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))
            ->from(url('/bancos/movimientos/bogota?estado=pendiente'))
            ->get('/bancos/movimiento/'.$movement->id);

        expect($response->viewData('back'))->toBe('/bancos/movimientos/bogota?estado=pendiente');
    });
});

describe('the menu', function () {
    it('has the movements and the loads, and counts the pending credits', function () {
        bancosStored();
        bancosStored(['bank' => Bank::Miami]);
        bancosStored(['status' => MovementStatus::Applied, 'receipt_number' => '1']);
        bancosStored(['company' => CompanyCode::SDC]);
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        $items = collect(app(NavRegistry::class)->visibleTo($user))->keyBy('key');

        expect($items->has('bancos.movimientos'))->toBeTrue()
            ->and($items->has('bancos.cargas'))->toBeTrue()
            ->and($items['bancos.movimientos']->badgeCount())->toBe(2);

        $this->fakes->currentCompany->switchTo(null);

        expect($items['bancos.movimientos']->badgeCount())->toBe(3);
    });

    it('shows nothing to users without access', function () {
        $items = collect(app(NavRegistry::class)->visibleTo(BancosUsers::create(Role::SalesAdvisor)))->pluck('key');

        expect($items->contains('bancos.movimientos'))->toBeFalse();
    });

    it('has no badge when nothing is pending', function () {
        $items = collect(app(NavRegistry::class)->visibleTo(BancosUsers::create(Role::PortfolioAnalyst)))->keyBy('key');

        expect($items['bancos.movimientos']->badgeCount())->toBeNull();
    });
});
