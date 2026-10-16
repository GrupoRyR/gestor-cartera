<?php

use App\Modules\Core\Contracts\Data\Commitment;
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
    $this->fakes->currentCompany->switchTo(null);
});

/**
 * Seeds a commitment with the few facts a test cares about.
 */
function inboxCommitment(
    string $client,
    CompanyCode $company = CompanyCode::SDC,
    CommitmentStatus $status = CommitmentStatus::Pending,
    float $amount = 1_000_000.0,
    int $dueInDays = 3,
    ?int $advisorCode = null,
    ?string $notes = null,
): Commitment {
    $commitment = Make::commitment(
        company: $company,
        clientId: Make::nextId(),
        clientName: $client,
        advisorCode: $advisorCode,
        amount: $amount,
        dueDate: CarbonImmutable::today()->addDays($dueInDays),
        status: $status,
        notes: $notes,
    );
    test()->fakes->compromisos->add($commitment);

    return $commitment;
}

it('serves the inbox at /cartera/compromisos', function () {
    expect(route('cartera.compromisos.index', absolute: false))->toBe('/cartera/compromisos');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cartera/compromisos')->assertRedirect('/login');
});

it('opens for the portfolio manager and the analyst only', function (?Role $role, int $status) {
    $this->actingAs(CarteraUsers::create($role))->get('/cartera/compromisos')->assertStatus($status);
})->with([
    'manager' => [Role::PortfolioManager, 200],
    'analyst' => [Role::PortfolioAnalyst, 200],
    'advisor' => [Role::SalesAdvisor, 403],
    'sales manager' => [Role::SalesManager, 403],
    'no role' => [null, 403],
]);

it('lists every commitment of both companies in the group view, the soonest first', function () {
    inboxCommitment('Cliente tardío', CompanyCode::SGX, dueInDays: 9);
    inboxCommitment('Cliente pronto', CompanyCode::SDC, dueInDays: 1);

    $this->actingAs(CarteraUsers::manager())
        ->get('/cartera/compromisos')
        ->assertOk()
        ->assertSeeInOrder(['Cliente pronto', 'SDC', 'Cliente tardío', 'SGX']);
});

it('shows each commitment with its value, state, author and notes', function () {
    $commitment = Make::commitment(
        company: CompanyCode::SDC,
        clientName: 'Cliente de SDC',
        advisorCode: 77,
        amount: 2_345_000,
        notes: 'Paga con cheque',
        createdBy: Make::userRef(name: 'María Paula Rojas'),
    );
    $this->fakes->compromisos->add($commitment);
    $this->fakes->cartera->addAdvisor(Make::advisor(company: CompanyCode::SDC, code: 77, name: 'Único asesor de SDC'));

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/compromisos')
        ->assertSeeText('Cliente de SDC')
        ->assertSeeText('Único asesor de SDC')
        ->assertSee(Money::format(2_345_000))
        ->assertSeeText('Pendiente')
        ->assertSeeText('María Paula Rojas')
        ->assertSeeText('Paga con cheque');
});

it('links each commitment to the file of its client', function () {
    $commitment = inboxCommitment('Cliente enlazado');

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/compromisos')
        ->assertSee('href="'.route('cartera.clientes.show', $commitment->clientId).'"', false);
});

describe('the tabs', function () {
    beforeEach(function () {
        inboxCommitment('Compromiso pendiente');
        inboxCommitment('Compromiso incumplido', status: CommitmentStatus::Broken, dueInDays: -2);
        inboxCommitment('Compromiso cumplido', status: CommitmentStatus::Fulfilled);
        inboxCommitment('Compromiso anulado', status: CommitmentStatus::Cancelled);
    });

    it('open on the pending ones', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos')
            ->assertSeeText('Compromiso pendiente')
            ->assertDontSeeText('Compromiso incumplido')
            ->assertDontSeeText('Compromiso cumplido')
            ->assertDontSeeText('Compromiso anulado');
    });

    it('show the status they name', function (string $tab, string $shown, array $hidden) {
        $response = $this->actingAs(CarteraUsers::analyst())->get("/cartera/compromisos?estado={$tab}")->assertSeeText($shown);

        foreach ($hidden as $name) {
            $response->assertDontSeeText($name);
        }
    })->with([
        'broken' => ['incumplidos', 'Compromiso incumplido', ['Compromiso pendiente', 'Compromiso cumplido', 'Compromiso anulado']],
        'fulfilled' => ['cumplidos', 'Compromiso cumplido', ['Compromiso pendiente', 'Compromiso incumplido', 'Compromiso anulado']],
        'cancelled' => ['anulados', 'Compromiso anulado', ['Compromiso pendiente', 'Compromiso incumplido', 'Compromiso cumplido']],
    ]);

    it('can show them all', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?estado=todos')
            ->assertSeeText('Compromiso pendiente')
            ->assertSeeText('Compromiso incumplido')
            ->assertSeeText('Compromiso cumplido')
            ->assertSeeText('Compromiso anulado');
    });

    it('are links that keep the filters', function () {
        $html = $this->actingAs(CarteraUsers::analyst())->get('/cartera/compromisos?asesor=5&desde=2026-10-01')->getContent();

        expect($html)->toContain('estado=incumplidos&amp;asesor=5&amp;desde=2026-10-01');
    });
});

describe('the filters', function () {
    it('narrow the list by advisor', function () {
        $carlos = Make::advisor(company: CompanyCode::SGX, name: 'Carlos Pérez');
        $laura = Make::advisor(company: CompanyCode::SGX, name: 'Laura Gómez');
        $this->fakes->cartera->addAdvisor($carlos, $laura);
        inboxCommitment('Compromiso de Carlos', CompanyCode::SGX, advisorCode: $carlos->code);
        inboxCommitment('Compromiso de Laura', CompanyCode::SGX, advisorCode: $laura->code);

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?asesor='.$laura->code)
            ->assertSeeText('Compromiso de Laura')
            ->assertDontSeeText('Compromiso de Carlos');
    });

    it('narrow the list by due date, both ends included', function () {
        $from = CarbonImmutable::today()->addDays(2);
        $to = CarbonImmutable::today()->addDays(4);
        inboxCommitment('Antes del rango', dueInDays: 1);
        inboxCommitment('Primer día del rango', dueInDays: 2);
        inboxCommitment('Último día del rango', dueInDays: 4);
        inboxCommitment('Después del rango', dueInDays: 5);

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?desde='.$from->toDateString().'&hasta='.$to->toDateString())
            ->assertSeeText('Primer día del rango')
            ->assertSeeText('Último día del rango')
            ->assertDontSeeText('Antes del rango')
            ->assertDontSeeText('Después del rango');
    });

    it('open anyway when the address has values they do not know', function () {
        inboxCommitment('Compromiso cualquiera');

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?estado=raro&asesor=x&desde=ayer&sort=clave&direction=de-lado')
            ->assertOk()
            ->assertSeeText('Compromiso cualquiera');
    });

    it('say what to do when they leave nothing', function () {
        inboxCommitment('Compromiso cualquiera');

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?asesor=999')
            ->assertSeeText('Ningún compromiso coincide con los filtros')
            ->assertSee('href="'.route('cartera.compromisos.index').'"', false);
    });

    it('offer only the advisors of the companies in view', function () {
        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
        $this->fakes->cartera->addAdvisor(Make::advisor(company: CompanyCode::SGX, name: 'Asesor de SGX'), Make::advisor(company: CompanyCode::SDC, name: 'Asesor de SDC'));

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos')
            ->assertSeeText('Asesor de SDC')
            ->assertDontSeeText('Asesor de SGX');
    });
});

it('sorts by the column the user picks', function () {
    inboxCommitment('Valor pequeño', amount: 100_000);
    inboxCommitment('Valor grande', amount: 9_000_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/compromisos?sort=amount&direction=desc')
        ->assertSeeInOrder(['Valor grande', 'Valor pequeño']);
});

it('pages the list keeping the tab and the filters', function () {
    config(['cartera.por_pagina' => 2]);

    foreach (['Uno', 'Dos', 'Tres'] as $step => $name) {
        inboxCommitment("Compromiso {$name}", dueInDays: $step + 1);
    }

    $first = $this->actingAs(CarteraUsers::analyst())->get('/cartera/compromisos?estado=pendientes&desde='.CarbonImmutable::today()->toDateString());

    $first->assertSeeText('Compromiso Uno')->assertSeeText('Compromiso Dos')->assertDontSeeText('Compromiso Tres')
        ->assertSee('page=2', false);

    $this->actingAs(CarteraUsers::analyst())->get('/cartera/compromisos?page=2')->assertSeeText('Compromiso Tres');
});

it('adds up the value of the page', function () {
    inboxCommitment('Uno', amount: 1_000_000);
    inboxCommitment('Dos', amount: 2_500_000);

    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/compromisos')
        ->assertSeeText('Total de esta página')
        ->assertSee(Money::format(3_500_000));
});

it('explains an empty tab', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->get('/cartera/compromisos')
        ->assertSeeText('No hay compromisos en esta pestaña');
});

it('tells that SGX commitments are read-only here', function () {
    $this->actingAs(CarteraUsers::manager())
        ->get('/cartera/compromisos')
        ->assertSeeText('En SGX los compromisos los registra el asesor del cliente');
});

describe('marking a commitment as fulfilled from the inbox', function () {
    it('is offered to the analyst on open SDC commitments only', function () {
        $sdc = inboxCommitment('Compromiso de SDC', CompanyCode::SDC);
        $broken = inboxCommitment('Compromiso roto de SDC', CompanyCode::SDC, CommitmentStatus::Broken, dueInDays: -1);
        $sgx = inboxCommitment('Compromiso de SGX', CompanyCode::SGX);

        $html = $this->actingAs(CarteraUsers::analyst())->get('/cartera/compromisos?estado=todos')->getContent();

        expect($html)->toContain('action="'.route('cartera.compromisos.cumplir', $sdc->id).'"')
            ->and($html)->not->toContain('action="'.route('cartera.compromisos.cumplir', $sgx->id).'"');

        $html = $this->actingAs(CarteraUsers::analyst())->get('/cartera/compromisos?estado=incumplidos')->getContent();

        expect($html)->toContain('action="'.route('cartera.compromisos.cumplir', $broken->id).'"');
    });

    it('is not offered on a commitment that is closed', function () {
        $fulfilled = inboxCommitment('Compromiso cumplido', status: CommitmentStatus::Fulfilled);

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?estado=cumplidos')
            ->assertDontSee(route('cartera.compromisos.cumplir', $fulfilled->id), false);
    });

    it('is not offered to the manager, who only reads', function () {
        $commitment = inboxCommitment('Compromiso de SDC');

        $this->actingAs(CarteraUsers::manager())
            ->get('/cartera/compromisos')
            ->assertSeeText('Compromiso de SDC')
            ->assertDontSee(route('cartera.compromisos.cumplir', $commitment->id), false);
    });
});

it('is linked from the menu and from the dashboard', function () {
    CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 1_000_000);
    $analyst = CarteraUsers::analyst();

    $this->actingAs($analyst)->get('/cartera/compromisos')->assertSee('href="'.route('cartera.compromisos.index').'"', false);
    $this->actingAs($analyst)->get('/cartera')->assertSee('href="'.route('cartera.compromisos.index').'', false);
});
