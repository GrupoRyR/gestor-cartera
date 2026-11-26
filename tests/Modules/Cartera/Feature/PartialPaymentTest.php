<?php

use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

/*
 * The analyst and the manager record that a client paid only part of a
 * commitment ("abono", cartera.commitments.status). Core's CompromisoService
 * stays the authority; its fake follows the same amount and status rules.
 */
beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->currentCompany->switchTo(null);
    $this->client = CarteraWorld::client($this->fakes, name: 'Agro Arango SAS', company: CompanyCode::SDC, overdue: 2_000_000);
});

/**
 * Seeds a commitment of $client (the SDC client of the test when null)
 * worth 1.000.000 and returns it.
 */
function abonoCommitment(CommitmentStatus $status = CommitmentStatus::Pending, ?ClientSummary $client = null, ?float $paidAmount = null): Commitment
{
    $client ??= test()->client;
    $commitment = Make::commitment(
        company: $client->company,
        clientId: $client->id,
        clientName: $client->name,
        amount: 1_000_000.0,
        dueDate: CarbonImmutable::today()->addDays(3),
        status: $status,
        paidAmount: $paidAmount,
        partiallyPaidAt: $paidAmount === null ? null : CarbonImmutable::now(),
    );
    test()->fakes->compromisos->add($commitment);

    return $commitment;
}

/**
 * A «commitment.remaining» notice as Core stores it (docs/arquitectura.md §6.5).
 *
 * @param  array<string, mixed>  $data
 */
function remainingBalanceNotice(?string $subjectType = 'client', ?int $subjectId = 41, string $company = 'SDC', array $data = ['commitment_id' => 9, 'paid_amount' => 1_000_000.0, 'remaining' => 1_500_000.0]): Notice
{
    return new Notice(
        id: 1,
        userId: 1,
        company: $company,
        kind: 'commitment.remaining',
        title: 'Agro Arango SAS: abonó $ 1.000.000, queda $ 1.500.000',
        body: 'Crea un nuevo compromiso por el saldo.',
        subjectType: $subjectType,
        subjectId: $subjectId,
        key: "commitment.remaining:{$subjectId}",
        data: $data,
        readAt: null,
        resolvedAt: null,
        createdAt: CarbonImmutable::now(),
    );
}

it('serves the partial payment route under /cartera', function () {
    expect(route('cartera.compromisos.abonar', 5, absolute: false))->toBe('/cartera/compromisos/5/abonar');
});

it('sends guests to the sign-in page', function () {
    $this->post('/cartera/compromisos/1/abonar')->assertRedirect('/login');
});

it('lets the analyst and the manager record a partial payment of a pending or broken commitment', function (Role $role, CommitmentStatus $status) {
    $commitment = abonoCommitment($status);

    $this->actingAs(CarteraUsers::create($role))
        ->from('/cartera/compromisos')
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '400000', 'nota' => 'Pagó por transferencia']])
        ->assertRedirect('/cartera/compromisos')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Registraste el abono.');

    $paid = $this->fakes->compromisos->find($commitment->id);

    expect($paid->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and($paid->paidAmount)->toBe(400000.0)
        ->and($paid->remaining())->toBe(600000.0);
})->with([Role::PortfolioAnalyst, Role::PortfolioManager])->with([CommitmentStatus::Pending, CommitmentStatus::Broken]);

it('records it in an SGX commitment too', function () {
    $sgx = CarteraWorld::client($this->fakes, name: 'Cliente de SGX', company: CompanyCode::SGX, overdue: 1_000_000);
    $commitment = abonoCommitment(client: $sgx);

    $this->actingAs(CarteraUsers::manager())
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '250000']])
        ->assertSessionHas('status', 'Registraste el abono.');

    expect($this->fakes->compromisos->find($commitment->id)->paidAmount)->toBe(250000.0);
});

it('is closed to everyone else, the superadmin included', function (?Role $role) {
    $commitment = abonoCommitment();
    $user = $role === null ? CarteraUsers::viewer() : CarteraUsers::create($role);

    $this->actingAs($user)
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '400000']])
        ->assertForbidden();

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Pending);
})->with([
    'superadmin' => [Role::SuperAdmin],
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'only cartera.view' => [null],
]);

it('answers 403 when Core refuses and 404 for a commitment that does not exist', function () {
    $commitment = abonoCommitment();
    $manager = CarteraUsers::manager();

    $this->actingAs($manager)->post('/cartera/compromisos/987654/abonar', ['abono_987654' => ['valor' => '1000']])->assertNotFound();

    $this->fakes->compromisos->denyAuthorization();

    $this->actingAs($manager)->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '1000']])->assertForbidden();
});

it('says a closed commitment cannot change', function (CommitmentStatus $status) {
    $commitment = abonoCommitment($status, paidAmount: $status === CommitmentStatus::PartiallyPaid ? 100_000.0 : null);

    $this->actingAs(CarteraUsers::analyst())
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '400000']])
        ->assertSessionHas('error', 'Este compromiso ya no se puede cambiar.');

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe($status);
})->with([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled, CommitmentStatus::PartiallyPaid]);

it('needs a paid amount above zero and below the commitment value', function (mixed $value) {
    $commitment = abonoCommitment();

    $this->actingAs(CarteraUsers::analyst())
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => $value]])
        ->assertSessionHasErrors("abono_{$commitment->id}.valor");

    expect($this->fakes->compromisos->find($commitment->id)->status)->toBe(CommitmentStatus::Pending);
})->with([
    'missing' => [null],
    'zero' => ['0'],
    'negative' => ['-100'],
    'not a number' => ['mucho'],
    'the whole value' => ['1000000'],
    'more than the value' => ['1500000'],
]);

it('explains that a full payment fulfils the commitment', function () {
    $commitment = abonoCommitment();

    $this->actingAs(CarteraUsers::analyst())
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '1000000']])
        ->assertSessionHasErrors(["abono_{$commitment->id}.valor" => 'El abono debe ser mayor que cero y menor que el valor del compromiso. Si pagó todo, márcalo cumplido.']);
});

it('limits the note', function () {
    $commitment = abonoCommitment();

    $this->actingAs(CarteraUsers::analyst())
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '1000', 'nota' => str_repeat('a', 1001)]])
        ->assertSessionHasErrors("abono_{$commitment->id}.nota");
});

it('reopens the partial payment form that failed, in the inbox and in the client file', function (string $page) {
    $commitment = abonoCommitment();
    $page = str_replace('{client}', (string) $this->client->id, $page);

    $html = $this->actingAs(CarteraUsers::analyst())
        ->followingRedirects()
        ->from($page)
        ->post("/cartera/compromisos/{$commitment->id}/abonar", ["abono_{$commitment->id}" => ['valor' => '1000000', 'nota' => 'Nota que se conserva']])
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/<dialog[^>]*id="compromiso-'.$commitment->id.'-abono"[^>]*data-modal-autoopen/s')
        ->and($html)->not->toMatch('/<dialog[^>]*id="compromiso-nuevo"[^>]*data-modal-autoopen/s')
        ->and($html)->toContain('Si pagó todo, márcalo cumplido.')
        ->and($html)->toContain('Nota que se conserva');
})->with(['inbox' => ['/cartera/compromisos'], 'client file' => ['/cartera/clientes/{client}']]);

describe('the button', function () {
    it('shows on pending and broken commitments only, in the inbox and the client file', function (Role $role) {
        $pending = abonoCommitment();
        $broken = abonoCommitment(CommitmentStatus::Broken);
        $fulfilled = abonoCommitment(CommitmentStatus::Fulfilled);
        $user = CarteraUsers::create($role);

        foreach (['/cartera/compromisos?estado=todos', "/cartera/clientes/{$this->client->id}"] as $page) {
            $html = $this->actingAs($user)->get($page)->assertOk()->getContent();

            foreach ([$pending, $broken] as $open) {
                expect($html)->toContain('data-modal-open="compromiso-'.$open->id.'-abono"')
                    ->and($html)->toMatch('/<dialog[^>]*id="compromiso-'.$open->id.'-abono"/')
                    ->and($html)->toContain('action="'.route('cartera.compromisos.abonar', $open->id).'"');
            }

            expect($html)->not->toContain('compromiso-'.$fulfilled->id.'-abono')
                ->and($html)->toContain('Valor pagado')
                ->and($html)->toContain('El compromiso es por '.Money::format(1_000_000));
        }
    })->with([Role::PortfolioAnalyst, Role::PortfolioManager]);

    it('takes the paid amount in a whole-peso money input', function () {
        $commitment = abonoCommitment();

        $html = $this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}")->assertOk()->getContent() ?: '';

        $field = Html::parse($html)->one('//input[@name="abono_'.$commitment->id.'[valor]"]');

        expect($field->getAttribute('type'))->toBe('text')
            ->and($field->hasAttribute('data-money-input'))->toBeTrue()
            ->and($field->getAttribute('data-decimals'))->toBe('0')
            ->and($field->getAttribute('inputmode'))->toBe('numeric')
            ->and($field->hasAttribute('required'))->toBeTrue()
            ->and($html)->toContain('El compromiso es por '.Money::format(1_000_000).'. En pesos colombianos.')
            ->and($html)->not->toContain('sin puntos ni comas');
    });

    it('does not show without the permission', function () {
        $commitment = abonoCommitment();

        $this->actingAs(CarteraUsers::create(Role::SuperAdmin))
            ->get('/cartera/compromisos')
            ->assertOk()
            ->assertSeeText('Agro Arango SAS')
            ->assertDontSee('compromiso-'.$commitment->id.'-abono', false)
            ->assertDontSee(route('cartera.compromisos.abonar', $commitment->id), false);
    });
});

describe('a partially paid commitment', function () {
    it('shows what was paid and what is left, in the inbox and the client file', function () {
        abonoCommitment(CommitmentStatus::PartiallyPaid, paidAmount: 400_000.0);
        $line = 'Pagó '.Money::format(400_000).' · saldo '.Money::format(600_000);

        $this->actingAs(CarteraUsers::manager())->get('/cartera/compromisos?estado=todos')->assertOk()->assertSee($line);
        $this->actingAs(CarteraUsers::manager())->get("/cartera/clientes/{$this->client->id}")->assertOk()->assertSee($line);
    });

    it('has its own tab in the inbox', function () {
        abonoCommitment(CommitmentStatus::PartiallyPaid, paidAmount: 400_000.0);
        $other = CarteraWorld::client($this->fakes, name: 'Cliente pendiente', company: CompanyCode::SDC);
        abonoCommitment(client: $other);

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/compromisos?estado=abonados')
            ->assertOk()
            ->assertSeeText('Abonados')
            ->assertSeeText('Agro Arango SAS')
            ->assertDontSeeText('Cliente pendiente');
    });
});

describe('the link of the balance notice', function () {
    it('opens the client file with a new commitment for the balance for the analyst of an SDC client', function () {
        $this->actingAs(CarteraUsers::analyst());

        expect(app(EloquentAvisoService::class)->link(remainingBalanceNotice()))
            ->toBe(route('cartera.clientes.show', ['client' => 41, 'nuevo-compromiso' => 1, 'valor' => '1500000']));
    });

    it('opens only the client file for whoever does not write the commitments', function (Role $role, string $company) {
        $this->actingAs(CarteraUsers::create($role));

        expect(app(EloquentAvisoService::class)->link(remainingBalanceNotice(company: $company)))
            ->toBe(route('cartera.clientes.show', 41));
    })->with([
        'manager, advisor-written company' => [Role::PortfolioManager, 'SGX'],
        'manager, SDC' => [Role::PortfolioManager, 'SDC'],
        'analyst, advisor-written company' => [Role::PortfolioAnalyst, 'SGX'],
    ]);

    it('leaves out a balance that is not a usable amount', function () {
        $this->actingAs(CarteraUsers::analyst());

        expect(app(EloquentAvisoService::class)->link(remainingBalanceNotice(data: ['remaining' => 'mucho'])))
            ->toBe(route('cartera.clientes.show', ['client' => 41, 'nuevo-compromiso' => 1]));
    });

    it('passes for users without Cartera, guests and notices about something else', function () {
        $avisos = app(EloquentAvisoService::class);

        expect($avisos->link(remainingBalanceNotice()))->toBeNull();

        $this->actingAs(CarteraUsers::create(Role::SalesAdvisor));
        expect($avisos->link(remainingBalanceNotice()))->toBeNull();

        $this->actingAs(CarteraUsers::analyst());
        expect($avisos->link(remainingBalanceNotice(subjectType: 'commitment')))->toBeNull()
            ->and($avisos->link(remainingBalanceNotice(subjectId: null)))->toBeNull();
    });
});

describe('the new commitment form', function () {
    it('opens from the link with the balance filled in', function () {
        $html = $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$this->client->id}?nuevo-compromiso=1&valor=1500000")
            ->assertOk()
            ->getContent();

        expect($html)->toMatch('/<dialog[^>]*id="compromiso-nuevo"[^>]*data-modal-autoopen/s')
            ->and($html)->toMatch('/name="compromiso\[valor\]"[^>]*value="1500000"/');
    });

    it('opens without a value when the one in the link is not an amount', function () {
        $html = $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$this->client->id}?nuevo-compromiso=1&valor=mucho")
            ->assertOk()
            ->getContent();

        expect($html)->toMatch('/<dialog[^>]*id="compromiso-nuevo"[^>]*data-modal-autoopen/s')
            ->and($html)->not->toContain('value="mucho"');
    });

    it('stays closed without the link', function () {
        $html = $this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}")->assertOk()->getContent();

        expect($html)->toMatch('/<dialog[^>]*id="compromiso-nuevo"/')
            ->and($html)->not->toMatch('/<dialog[^>]*id="compromiso-nuevo"[^>]*data-modal-autoopen/s');
    });

    it('does not open for whoever cannot write commitments', function () {
        $this->actingAs(CarteraUsers::manager())
            ->get("/cartera/clientes/{$this->client->id}?nuevo-compromiso=1&valor=1500000")
            ->assertOk()
            ->assertDontSee('id="compromiso-nuevo"', false);
    });
});
