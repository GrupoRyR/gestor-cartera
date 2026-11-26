<?php

use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Core\Support\CommitmentRecords;

/*
 * The advisor records that the client paid only part of a commitment
 * ("abono"), against the real CompromisoService, which closes it as
 * partially paid and tells the advisor to promise the balance.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
    $this->fileUrl = route('asesores.clientes.show', $this->agro->id);

    $this->pending = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-30', '2000000.00');
    $this->broken = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-10', '1000000.00', ['status' => CommitmentStatus::Broken, 'broken_at' => '2026-10-11 00:15:00']);
    $this->fulfilled = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-01', '500000.00', ['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => '2026-10-02 09:00:00']);
});

/**
 * The abono form of a commitment page, as the browser sends it.
 *
 * @return array{abono: array<string, string>}
 */
function asesoresAbono(string $value, ?string $note = null): array
{
    return ['abono' => array_filter(['valor' => $value, 'nota' => $note], fn (?string $field): bool => $field !== null)];
}

/**
 * A «commitment.remaining» notice as Core stores it (docs/arquitectura.md §6.5).
 *
 * @param  array<string, mixed>  $data
 */
function asesoresRemainingNotice(int $clientId, string $company = 'SGX', ?string $subjectType = 'client', bool $withSubject = true, array $data = ['commitment_id' => 9, 'paid_amount' => 500_000.0, 'remaining' => 1_500_000.0]): Notice
{
    return new Notice(
        id: 1,
        userId: 1,
        company: $company,
        kind: 'commitment.remaining',
        title: 'Agro Arango: abonó $ 500.000, queda $ 1.500.000',
        body: 'Crea un nuevo compromiso por el saldo.',
        subjectType: $subjectType,
        subjectId: $withSubject ? $clientId : null,
        key: "commitment.remaining:{$clientId}",
        data: $data,
        readAt: null,
        resolvedAt: null,
        createdAt: CarbonImmutable::now(),
    );
}

// --- recording it -------------------------------------------------------------

it('serves the partial payment under /asesores', function () {
    expect(route('asesores.compromisos.abonar', 5, absolute: false))->toBe('/asesores/compromisos/5/abonar');
});

it('records what the client paid of a pending or broken commitment, written as in Colombia', function (string $which, string $typed, float $paid) {
    $commitment = $this->{$which};

    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.abonar', $commitment->id), asesoresAbono($typed, 'Pagó por transferencia'))
        ->assertRedirect($this->fileUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Registraste el abono.');

    $saved = CommitmentRecords::stored($commitment);

    expect($saved->status)->toBe(CommitmentStatus::PartiallyPaid)
        ->and((float) $saved->paid_amount)->toBe($paid);
})->with([
    'pending' => ['pending', '1.500.000', 1_500_000.0],
    'broken' => ['broken', '$ 400.000,50', 400_000.5],
]);

it('keeps sales managers, analysts and guests from recording it', function () {
    $url = route('asesores.compromisos.abonar', $this->pending->id);

    $this->post($url, asesoresAbono('1000'))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->post($url, asesoresAbono('1000'))->assertForbidden();
    $this->actingAs($this->world->gloria)->post($url, asesoresAbono('1000'))->assertForbidden();

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending);
});

it('keeps it away from other advisors, as if the commitment did not exist', function () {
    $this->actingAs($this->world->bruno)->post(route('asesores.compromisos.abonar', $this->pending->id), asesoresAbono('1000'))->assertNotFound();
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.abonar', 987654), asesoresAbono('1000'))->assertNotFound();

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending);
});

it('says so when the commitment was already closed, instead of failing', function () {
    $this->actingAs($this->world->ana)->from($this->fileUrl)->post(route('asesores.compromisos.abonar', $this->fulfilled->id), asesoresAbono('1000'))
        ->assertRedirect($this->fileUrl)
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'ya no se puede cambiar'));

    expect(CommitmentRecords::stored($this->fulfilled)->status)->toBe(CommitmentStatus::Fulfilled);
});

it('needs a paid amount above zero and below the commitment value', function (string $typed) {
    $this->actingAs($this->world->ana)->from(route('asesores.compromisos.show', $this->pending->id))
        ->post(route('asesores.compromisos.abonar', $this->pending->id), asesoresAbono($typed))
        ->assertSessionHasErrors('abono.valor');

    expect(CommitmentRecords::stored($this->pending)->status)->toBe(CommitmentStatus::Pending);
})->with([
    'missing' => [''],
    'zero' => ['0'],
    'not an amount' => ['mucho'],
    'the whole value' => ['2.000.000'],
    'more than the value' => ['3.000.000'],
]);

it('explains that a full payment fulfils the commitment', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.abonar', $this->pending->id), asesoresAbono('2.000.000'))
        ->assertSessionHasErrors(['abono.valor' => 'El abono debe ser mayor que cero y menor que el valor del compromiso. Si pagó todo, márcalo cumplido.']);
});

it('limits the note like the one of a fulfilled commitment', function () {
    $this->actingAs($this->world->ana)->post(route('asesores.compromisos.abonar', $this->pending->id), asesoresAbono('1000', str_repeat('a', 256)))
        ->assertSessionHasErrors('abono.nota');
});

it('shows the error and what was typed in the abono card only', function () {
    $page = $this->actingAs($this->world->ana)->followingRedirects()->from(route('asesores.compromisos.show', $this->pending->id))
        ->post(route('asesores.compromisos.abonar', $this->pending->id), asesoresAbono('3.000.000', 'Nota que se conserva'))
        ->assertOk();
    $html = Page::html($page);

    expect($html->text('//form[contains(@action,"/abonar")]//*[@data-part="error"]'))->toContain('Si pagó todo, márcalo cumplido.')
        ->and($html->one('//form[contains(@action,"/abonar")]//input[@name="abono[valor]"]')->getAttribute('value'))->toBe('3.000.000')
        ->and($html->one('//form[contains(@action,"/abonar")]//input[@name="abono[nota]"]')->getAttribute('value'))->toBe('Nota que se conserva')
        ->and($html->count('//form[not(contains(@action,"/abonar"))]//*[@data-part="error"]'))->toBe(0)
        ->and($html->one('//form[input[@name="_method"][@value="PUT"]]//input[@name="valor"]')->getAttribute('value'))->toBe('2000000')
        ->and($html->one('//form[contains(@action,"/cumplir")]//input[@name="nota"]')->getAttribute('value'))->toBe('');
});

// --- the commitment page --------------------------------------------------------

it('offers the abono card wherever the commitment can be fulfilled', function (string $which, int $cards) {
    $commitment = $this->{$which};
    $response = $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $commitment->id))->assertOk();

    expect(Page::html($response)->count('//form[@action="'.route('asesores.compromisos.abonar', $commitment->id).'"]'))->toBe($cards)
        ->and(Page::html($response)->count('//form[contains(@action,"/cumplir")]'))->toBe($cards);

    if ($cards === 1) {
        $response->assertSeeText('Registrar abono')
            ->assertSeeText('Valor pagado')
            ->assertSeeText('El compromiso es por '.Money::format($commitment->amount));
    }
})->with([
    'pending' => ['pending', 1],
    'broken' => ['broken', 1],
    'fulfilled' => ['fulfilled', 0],
]);

it('does not offer it to who only reads the commitment', function () {
    $commitment = CommitmentRecords::commitment($this->world->clients['comercial'], $this->world->gloria, '2026-10-30');

    $response = $this->actingAs($this->world->gloria)->get(route('asesores.compromisos.show', $commitment->id))->assertOk();

    expect(Page::html($response)->count('//form[contains(@action,"/abonar")]'))->toBe(0);
    $response->assertDontSeeText('Registrar abono');
});

it('shows what was paid and what is left of a partially paid commitment, on its page and in the client file', function () {
    $partial = CommitmentRecords::commitment($this->agro, $this->world->ana, '2026-10-10', '800000.00', [
        'status' => CommitmentStatus::PartiallyPaid, 'paid_amount' => '300000.00', 'partially_paid_at' => '2026-10-11 10:00:00',
    ]);
    $line = 'Pagó '.Money::format(300_000).' · saldo '.Money::format(500_000);

    $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $partial->id))->assertOk()->assertSeeText($line);
    $this->actingAs($this->world->ana)->get($this->fileUrl)->assertOk()->assertSeeText($line);
    $this->actingAs($this->world->ana)->get(route('asesores.compromisos.show', $this->pending->id))->assertOk()->assertDontSeeText('· saldo');
});

// --- the new commitment for the balance -----------------------------------------

it('opens the new commitment with the balance from the link of the notice', function (string $query, ?string $value) {
    $html = Page::html($this->actingAs($this->world->ana)->get($this->fileUrl.$query)->assertOk());

    expect($html->count('//dialog[@id="nuevo-compromiso"][@data-modal-autoopen]'))->toBe(1)
        ->and($html->one('//dialog[@id="nuevo-compromiso"]//input[@name="valor"]')->getAttribute('value'))->toBe($value ?? '');
})->with([
    'whole pesos' => ['?nuevo-compromiso=1&valor=1500000', '1500000'],
    'with cents' => ['?nuevo-compromiso=1&valor=1500000.5', '1500000.5'],
    'cents written as in Colombia' => ['?nuevo-compromiso=1&valor=1.500.000,50', '1500000.5'],
    'written as in Colombia' => ['?nuevo-compromiso=1&valor=1.500.000', '1500000'],
    'not an amount' => ['?nuevo-compromiso=1&valor=mucho', null],
    'no amount' => ['?nuevo-compromiso=1', null],
]);

it('keeps the new commitment closed without the link', function () {
    $html = Page::html($this->actingAs($this->world->ana)->get($this->fileUrl.'?valor=1500000')->assertOk());

    expect($html->count('//dialog[@id="nuevo-compromiso"]'))->toBe(1)
        ->and($html->count('//dialog[@id="nuevo-compromiso"][@data-modal-autoopen]'))->toBe(0)
        ->and($html->one('//dialog[@id="nuevo-compromiso"]//input[@name="valor"]')->getAttribute('value'))->toBe('');
});

it('does not open it for who cannot write commitments', function () {
    $client = $this->world->clients['comercial'];

    $html = Page::html($this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $client->id).'?nuevo-compromiso=1&valor=1500000')->assertOk());

    expect($html->count('//dialog'))->toBe(0);
});

// --- the link of the notice ------------------------------------------------------

describe('the link of the balance notice', function () {
    it('opens the new commitment for the balance for the advisor who writes it', function () {
        $this->actingAs($this->world->ana);

        expect(app(EloquentAvisoService::class)->link(asesoresRemainingNotice($this->agro->id)))
            ->toBe(route('asesores.clientes.show', ['client' => $this->agro->id, 'nuevo-compromiso' => 1, 'valor' => '1500000']));
    });

    it('leaves out a balance that is not a usable amount', function () {
        $this->actingAs($this->world->ana);

        expect(app(EloquentAvisoService::class)->link(asesoresRemainingNotice($this->agro->id, data: ['remaining' => 'mucho'])))
            ->toBe(route('asesores.clientes.show', ['client' => $this->agro->id, 'nuevo-compromiso' => 1]));
    });

    it('opens only the client file for who views without writing', function () {
        $this->actingAs($this->world->gloria);

        expect(app(EloquentAvisoService::class)->link(asesoresRemainingNotice($this->agro->id)))
            ->toBe(route('asesores.clientes.show', $this->agro->id));
    });

    it('passes for guests, companies where the advisor does not write and notices about something else', function () {
        $avisos = app(EloquentAvisoService::class);

        expect($avisos->link(asesoresRemainingNotice($this->agro->id)))->toBeNull();

        $this->actingAs($this->world->ana);

        expect($avisos->link(asesoresRemainingNotice($this->agro->id, company: 'SDC')))->toBeNull()
            ->and($avisos->link(asesoresRemainingNotice($this->agro->id, subjectType: 'commitment')))->toBeNull()
            ->and($avisos->link(asesoresRemainingNotice($this->agro->id, withSubject: false)))->toBeNull();
    });

    it('leaves the portfolio team on the Cartera client file', function () {
        $this->actingAs(CarteraUsers::create(Role::PortfolioAnalyst));

        expect(app(EloquentAvisoService::class)->link(asesoresRemainingNotice($this->agro->id)))
            ->toBe(route('cartera.clientes.show', $this->agro->id));
    });
});
