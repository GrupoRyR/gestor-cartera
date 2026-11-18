<?php

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;

/*
 * The portfolio analyst and manager mark any commitment fulfilled or broken,
 * in SGX and SDC (cartera.commitments.status). Core's CompromisoService
 * stays the authority; the fake only records the change.
 */
beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->currentCompany->switchTo(null);
});

/**
 * Seeds a commitment an advisor created and returns it.
 */
function statusCommitment(CompanyCode $company = CompanyCode::SGX, CommitmentStatus $status = CommitmentStatus::Pending): Commitment
{
    $commitment = Make::commitment(
        company: $company,
        clientId: Make::nextId(),
        clientName: "Cliente de {$company->value}",
        advisorCode: 12,
        dueDate: CarbonImmutable::today()->addDays(2),
        status: $status,
    );
    test()->fakes->compromisos->add($commitment);

    return $commitment;
}

it('serves the broken route under /cartera', function () {
    expect(route('cartera.compromisos.incumplir', 5, absolute: false))->toBe('/cartera/compromisos/5/incumplir');
});

it('sends guests to the sign-in page', function () {
    $this->post('/cartera/compromisos/1/incumplir')->assertRedirect('/login');
});

it('lets the analyst and the manager mark a commitment of either company fulfilled', function (Role $role, CompanyCode $company) {
    $commitment = statusCommitment($company);

    $this->actingAs(CarteraUsers::create($role))
        ->post("/cartera/compromisos/{$commitment->id}/cumplir")
        ->assertSessionHas('status', 'Compromiso marcado como cumplido.');

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Fulfilled);
})->with([Role::PortfolioAnalyst, Role::PortfolioManager])->with([CompanyCode::SGX, CompanyCode::SDC]);

it('lets the analyst and the manager mark a commitment of either company broken', function (Role $role, CompanyCode $company) {
    $commitment = statusCommitment($company);

    $this->actingAs(CarteraUsers::create($role))
        ->from('/cartera/compromisos?estado=pendientes')
        ->post("/cartera/compromisos/{$commitment->id}/incumplir")
        ->assertRedirect('/cartera/compromisos?estado=pendientes')
        ->assertSessionHas('status', 'Compromiso marcado como incumplido.');

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Broken);
})->with([Role::PortfolioAnalyst, Role::PortfolioManager])->with([CompanyCode::SGX, CompanyCode::SDC]);

it('says only a pending commitment can be marked broken', function (CommitmentStatus $status) {
    $commitment = statusCommitment(status: $status);

    $this->actingAs(CarteraUsers::manager())
        ->post("/cartera/compromisos/{$commitment->id}/incumplir")
        ->assertSessionHas('error', 'Solo un compromiso pendiente se puede marcar incumplido.');

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe($status);
})->with([CommitmentStatus::Broken, CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled]);

it('answers 404 for a commitment that does not exist', function () {
    $this->actingAs(CarteraUsers::manager())->post('/cartera/compromisos/999/incumplir')->assertNotFound();
});

it('answers 403 when Core refuses', function () {
    $commitment = statusCommitment();
    $this->fakes->compromisos->denyAuthorization();

    $this->actingAs(CarteraUsers::manager())->post("/cartera/compromisos/{$commitment->id}/incumplir")->assertForbidden();
    $this->actingAs(CarteraUsers::manager())->post("/cartera/compromisos/{$commitment->id}/cumplir")->assertForbidden();
});

it('is closed to everyone else, the superadmin included', function (?Role $role) {
    $commitment = statusCommitment();
    $user = $role === null ? CarteraUsers::viewer() : CarteraUsers::create($role);

    $this->actingAs($user)->post("/cartera/compromisos/{$commitment->id}/cumplir")->assertForbidden();
    $this->actingAs($user)->post("/cartera/compromisos/{$commitment->id}/incumplir")->assertForbidden();

    expect($this->fakes->compromisos->all())->toBe([$commitment]);
})->with([
    'superadmin' => [Role::SuperAdmin],
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'only cartera.view' => [null],
]);

describe('the inbox', function () {
    it('offers the analyst and the manager both status buttons on a pending commitment of either company', function (Role $role) {
        $sgx = statusCommitment(CompanyCode::SGX);
        $sdc = statusCommitment(CompanyCode::SDC);

        $html = $this->actingAs(CarteraUsers::create($role))->get('/cartera/compromisos')->assertOk()->getContent();

        foreach ([$sgx, $sdc] as $commitment) {
            expect($html)->toContain('action="'.route('cartera.compromisos.cumplir', $commitment->id).'"')
                ->and($html)->toContain('action="'.route('cartera.compromisos.incumplir', $commitment->id).'"');
        }
    })->with([Role::PortfolioAnalyst, Role::PortfolioManager]);

    it('offers only «Marcar cumplido» on a broken commitment', function () {
        $broken = statusCommitment(status: CommitmentStatus::Broken);

        $html = $this->actingAs(CarteraUsers::manager())->get('/cartera/compromisos?estado=incumplidos')->assertOk()->getContent();

        expect($html)->toContain('action="'.route('cartera.compromisos.cumplir', $broken->id).'"')
            ->and($html)->not->toContain('action="'.route('cartera.compromisos.incumplir', $broken->id).'"');
    });

    it('offers nothing on a closed commitment', function () {
        $fulfilled = statusCommitment(status: CommitmentStatus::Fulfilled);

        $html = $this->actingAs(CarteraUsers::manager())->get('/cartera/compromisos?estado=cumplidos')->assertOk()->getContent();

        expect($html)->not->toContain(route('cartera.compromisos.cumplir', $fulfilled->id))
            ->and($html)->not->toContain(route('cartera.compromisos.incumplir', $fulfilled->id));
    });

    it('offers no status button to the superadmin', function () {
        $commitment = statusCommitment();

        $this->actingAs(CarteraUsers::create(Role::SuperAdmin))
            ->get('/cartera/compromisos')
            ->assertOk()
            ->assertSeeText('Cliente de SGX')
            ->assertDontSee(route('cartera.compromisos.cumplir', $commitment->id), false)
            ->assertDontSee(route('cartera.compromisos.incumplir', $commitment->id), false)
            ->assertSeeText('En SGX los compromisos los registra el asesor del cliente; aquí los ves todos.');
    });
});
