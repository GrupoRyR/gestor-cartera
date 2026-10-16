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
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
    $this->client = CarteraWorld::client($this->fakes, name: 'Cliente de SDC', company: CompanyCode::SDC, overdue: 3_000_000);
    $this->file = "/cartera/clientes/{$this->client->id}";
    $this->tomorrow = CarbonImmutable::today()->addDay();
});

/**
 * Seeds a commitment of the SDC client and returns it.
 */
function sdcCommitment(CommitmentStatus $status = CommitmentStatus::Pending, float $amount = 1_000_000.0): Commitment
{
    $commitment = Make::commitment(
        company: CompanyCode::SDC,
        clientId: test()->client->id,
        clientName: 'Cliente de SDC',
        amount: $amount,
        dueDate: CarbonImmutable::today()->addDays(5),
        status: $status,
    );
    test()->fakes->compromisos->add($commitment);

    return $commitment;
}

it('serves the commitment routes under /cartera', function () {
    expect(route('cartera.compromisos.store', 12, absolute: false))->toBe('/cartera/clientes/12/compromisos')
        ->and(route('cartera.compromisos.update', 5, absolute: false))->toBe('/cartera/compromisos/5')
        ->and(route('cartera.compromisos.cumplir', 5, absolute: false))->toBe('/cartera/compromisos/5/cumplir')
        ->and(route('cartera.compromisos.anular', 5, absolute: false))->toBe('/cartera/compromisos/5/anular');
});

it('sends guests to the sign-in page', function () {
    $this->post("{$this->file}/compromisos", [])->assertRedirect('/login');
    $this->put('/cartera/compromisos/1', [])->assertRedirect('/login');
    $this->post('/cartera/compromisos/1/cumplir')->assertRedirect('/login');
    $this->post('/cartera/compromisos/1/anular', [])->assertRedirect('/login');
});

it('is closed to everyone but the analyst, the manager included', function (?Role $role) {
    $commitment = sdcCommitment();
    $user = $role === null ? CarteraUsers::viewer() : CarteraUsers::create($role);
    $payload = ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => '500000']];

    $this->actingAs($user)->post("{$this->file}/compromisos", $payload)->assertForbidden();
    $this->actingAs($user)->put("/cartera/compromisos/{$commitment->id}", ['edicion_'.$commitment->id => ['valor' => '1']])->assertForbidden();
    $this->actingAs($user)->post("/cartera/compromisos/{$commitment->id}/cumplir")->assertForbidden();
    $this->actingAs($user)->post("/cartera/compromisos/{$commitment->id}/anular", ['anulacion_'.$commitment->id => ['motivo' => 'x']])->assertForbidden();

    $this->fakes->compromisos->assertNothingCreated();
    expect($this->fakes->compromisos->all())->toBe([$commitment]);
})->with([
    'portfolio manager' => [Role::PortfolioManager],
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'only cartera.view' => [null],
]);

describe('creating a commitment', function () {
    it('registers it for the client with its date, value and notes', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => '750000', 'notas' => 'Paga por transferencia']])
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Compromiso registrado.');

        $this->fakes->compromisos->assertCreated(fn (Commitment $commitment): bool => $commitment->clientId === $this->client->id
            && $commitment->company === CompanyCode::SDC
            && $commitment->amount === 750000.0
            && $commitment->dueDate->toDateString() === $this->tomorrow->toDateString()
            && $commitment->notes === 'Paga por transferencia'
            && $commitment->status === CommitmentStatus::Pending);
    });

    it('may go without notes and may be due today', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => CarbonImmutable::today()->toDateString(), 'valor' => '100000']])
            ->assertSessionHasNoErrors();

        $this->fakes->compromisos->assertCreated(fn (Commitment $commitment): bool => $commitment->notes === null);
    });

    it('needs a date that is today or later', function (mixed $date) {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => $date, 'valor' => '100000']])
            ->assertSessionHasErrors('compromiso.fecha');

        $this->fakes->compromisos->assertNothingCreated();
    })->with([
        'missing' => [null],
        'yesterday' => fn () => [CarbonImmutable::yesterday()->toDateString()],
        'not a date' => ['pronto'],
        'a date that does not exist' => ['2026-02-31'],
        'another format' => ['16/10/2027'],
    ]);

    it('needs a value above zero', function (mixed $value) {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => $value]])
            ->assertSessionHasErrors('compromiso.valor');

        $this->fakes->compromisos->assertNothingCreated();
    })->with(['missing' => [null], 'zero' => ['0'], 'negative' => ['-100'], 'text' => ['mucho'], 'absurd' => ['99999999999999']]);

    it('limits the notes', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => '1000', 'notas' => str_repeat('a', 1001)]])
            ->assertSessionHasErrors('compromiso.notas');
    });

    it('answers 403 when Core refuses, as it does for a commitment of an SGX client', function () {
        $this->fakes->compromisos->denyAuthorization();

        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/compromisos", ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => '1000']])
            ->assertForbidden();
    });

    it('answers 404 for a client that does not exist or is out of reach', function () {
        $payload = ['compromiso' => ['fecha' => $this->tomorrow->toDateString(), 'valor' => '1000']];
        $analyst = CarteraUsers::analyst();

        $this->actingAs($analyst)->post('/cartera/clientes/987654/compromisos', $payload)->assertNotFound();

        $this->fakes->cartera->restrictToAdvisor(999);

        $this->actingAs($analyst)->post("{$this->file}/compromisos", $payload)->assertNotFound();
    });

    it('goes back to the client file when the form fails, whatever page sent it', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->from('/cartera/compromisos')
            ->post("{$this->file}/compromisos", ['compromiso' => ['valor' => '0']])
            ->assertRedirect($this->file);
    });
});

describe('editing a commitment', function () {
    it('changes the date, value and notes of a pending one', function () {
        $commitment = sdcCommitment();
        $key = "edicion_{$commitment->id}";

        $this->actingAs(CarteraUsers::analyst())
            ->put("/cartera/compromisos/{$commitment->id}", [$key => ['fecha' => $this->tomorrow->addDays(3)->toDateString(), 'valor' => '1250000', 'notas' => 'Nueva fecha acordada']])
            ->assertSessionHas('status', 'Compromiso actualizado.');

        $updated = $this->fakes->compromisos->find($commitment->id);

        expect($updated->amount)->toBe(1250000.0)
            ->and($updated->dueDate->toDateString())->toBe($this->tomorrow->addDays(3)->toDateString())
            ->and($updated->notes)->toBe('Nueva fecha acordada');
    });

    it('validates what it changes', function () {
        $commitment = sdcCommitment();
        $key = "edicion_{$commitment->id}";

        $this->actingAs(CarteraUsers::analyst())
            ->put("/cartera/compromisos/{$commitment->id}", [$key => ['fecha' => CarbonImmutable::yesterday()->toDateString(), 'valor' => '-1']])
            ->assertSessionHasErrors(["{$key}.fecha", "{$key}.valor"]);
    });

    it('says a commitment that is no longer pending cannot change', function () {
        $commitment = sdcCommitment(CommitmentStatus::Broken);

        $this->actingAs(CarteraUsers::analyst())
            ->put("/cartera/compromisos/{$commitment->id}", ["edicion_{$commitment->id}" => ['valor' => '1000']])
            ->assertSessionHas('error', 'Este compromiso ya no se puede cambiar.');
    });

    it('answers 404 for a commitment that does not exist', function () {
        $this->actingAs(CarteraUsers::analyst())->put('/cartera/compromisos/987654', ['edicion_987654' => ['valor' => '1000']])->assertNotFound();
    });
});

describe('fulfilling a commitment', function () {
    it('marks a pending or a broken one as fulfilled', function (CommitmentStatus $status) {
        $commitment = sdcCommitment($status);

        $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/compromisos/{$commitment->id}/cumplir")
            ->assertSessionHas('status', 'Compromiso marcado como cumplido.');

        expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Fulfilled);
    })->with([CommitmentStatus::Pending, CommitmentStatus::Broken]);

    it('says a commitment that is already closed cannot change', function () {
        $commitment = sdcCommitment(CommitmentStatus::Cancelled);

        $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/compromisos/{$commitment->id}/cumplir")
            ->assertSessionHas('error', 'Este compromiso ya no se puede cambiar.');
    });

    it('goes back to the page that sent the request', function () {
        $commitment = sdcCommitment();

        $this->actingAs(CarteraUsers::analyst())
            ->from('/cartera/compromisos?estado=incumplidos&asesor=3')
            ->post("/cartera/compromisos/{$commitment->id}/cumplir")
            ->assertRedirect('/cartera/compromisos?estado=incumplidos&asesor=3');
    });
});

describe('cancelling a commitment', function () {
    it('cancels it with the reason', function () {
        $commitment = sdcCommitment();

        $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/compromisos/{$commitment->id}/anular", ["anulacion_{$commitment->id}" => ['motivo' => 'El cliente pagó en otra cuenta']])
            ->assertSessionHas('status', 'Compromiso anulado.');

        expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Cancelled)
            ->and($this->fakes->compromisos->cancellationReason($commitment->id))->toBe('El cliente pagó en otra cuenta');
    });

    it('needs a reason', function (mixed $reason) {
        $commitment = sdcCommitment();

        $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/compromisos/{$commitment->id}/anular", ["anulacion_{$commitment->id}" => ['motivo' => $reason]])
            ->assertSessionHasErrors("anulacion_{$commitment->id}.motivo");

        expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Pending);
    })->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 501)]]);
});

describe('the client file', function () {
    it('lets the analyst register a commitment of an SDC client and act on the ones it has', function () {
        $pending = sdcCommitment();
        $broken = sdcCommitment(CommitmentStatus::Broken, 400_000.0);

        $html = $this->actingAs(CarteraUsers::analyst())->get($this->file)->assertOk()->getContent();

        expect($html)->toContain('data-modal-open="compromiso-nuevo"')
            ->and($html)->toContain('action="'.route('cartera.compromisos.store', $this->client->id).'"')
            ->and($html)->toContain('action="'.route('cartera.compromisos.cumplir', $pending->id).'"')
            ->and($html)->toContain('data-modal-open="compromiso-'.$pending->id.'-editar"')
            ->and($html)->toContain('data-modal-open="compromiso-'.$pending->id.'-anular"')
            ->and($html)->toContain('action="'.route('cartera.compromisos.cumplir', $broken->id).'"')
            ->and($html)->toContain('data-modal-open="compromiso-'.$broken->id.'-anular"')
            ->and($html)->not->toContain('data-modal-open="compromiso-'.$broken->id.'-editar"');
    });

    it('offers no actions on a commitment that is closed', function () {
        $fulfilled = sdcCommitment(CommitmentStatus::Fulfilled);
        $cancelled = sdcCommitment(CommitmentStatus::Cancelled);

        $html = $this->actingAs(CarteraUsers::analyst())->get($this->file)->assertOk()->getContent();

        expect($html)->not->toContain(route('cartera.compromisos.cumplir', $fulfilled->id))
            ->and($html)->not->toContain(route('cartera.compromisos.cumplir', $cancelled->id));
    });

    it('only shows the commitments to the manager', function () {
        $commitment = sdcCommitment();

        $html = $this->actingAs(CarteraUsers::manager())->get($this->file)->assertOk()->getContent();

        expect($html)->toContain(Money::format($commitment->amount))
            ->and($html)->not->toContain('data-modal-open="compromiso-nuevo"')
            ->and($html)->not->toContain(route('cartera.compromisos.cumplir', $commitment->id));
    });

    it('says SGX commitments are written by the advisor', function () {
        $this->fakes->currentCompany->switchTo(null);
        $sgx = CarteraWorld::client($this->fakes, name: 'Cliente de SGX', company: CompanyCode::SGX, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$sgx->id}")
            ->assertOk()
            ->assertDontSee('data-modal-open="compromiso-nuevo"', false)
            ->assertSeeText('En SGX los compromisos los registra el asesor del cliente');
    });

    it('reopens the form that failed, with what was typed', function () {
        $commitment = sdcCommitment();
        $key = "edicion_{$commitment->id}";

        $response = $this->actingAs(CarteraUsers::analyst())
            ->followingRedirects()
            ->from($this->file)
            ->put("/cartera/compromisos/{$commitment->id}", [$key => ['valor' => '0', 'notas' => 'Nota que se conserva']]);

        expect($response->getContent())->toMatch('/<dialog[^>]*id="compromiso-'.$commitment->id.'-editar"[^>]*data-modal-autoopen/s')
            ->and($response->getContent())->not->toMatch('/<dialog[^>]*id="compromiso-nuevo"[^>]*data-modal-autoopen/s')
            ->and($response->getContent())->toContain('Nota que se conserva');
    });
});
