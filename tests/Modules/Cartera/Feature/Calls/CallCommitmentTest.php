<?php

use App\Modules\Cartera\Application\Calls\AttachCommitment;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Cartera\Support\CarteraUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));

    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
    $this->client = Make::client(company: CompanyCode::SDC, name: 'Agro Arango SAS');
    $this->invoices = [
        Make::invoice(company: CompanyCode::SDC, clientId: $this->client->id, documentNumber: 'FV-123', dueAt: CarbonImmutable::parse('2026-10-15'), balance: 1_234_567.5),
        Make::invoice(company: CompanyCode::SDC, clientId: $this->client->id, documentNumber: 'FV-200', dueAt: CarbonImmutable::parse('2026-11-30'), balance: 500_000.0),
    ];
    $this->fakes->cartera->addClient(Make::clientDetail($this->client, contacts: [Make::contact(name: 'Juan Pérez', phone: '3001234567')]), ...$this->invoices);

    $this->other = Make::client(company: CompanyCode::SDC, name: 'Otra Empresa SAS');
    $this->fakes->cartera->addClient(Make::clientDetail($this->other));

    $this->analyst = CarteraUsers::analyst();
    $this->file = "/cartera/clientes/{$this->client->id}";
});

/**
 * A call to the client agreed as a payment commitment on 18/11/2026, with the attributes each test changes.
 *
 * @param  array<string, mixed>  $attributes
 */
function agreedCall(array $attributes = []): CallRecord
{
    return CallRecord::factory()->forClient(test()->client->id, CompanyCode::SDC)->create($attributes + [
        'agreement' => CallAgreement::PaymentCommitment,
        'occurred_at' => CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Bogota'),
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 * @return array{compromiso: array<string, mixed>}
 */
function commitmentForm(array $fields = []): array
{
    return ['compromiso' => $fields + ['fecha' => '2026-11-27', 'valor' => '1734567.50']];
}

/**
 * A call form for the client with $agreement and the given invoices.
 *
 * @param  list<int>  $invoices
 * @return array{llamada: array<string, mixed>}
 */
function agreementCall(string $agreement, array $invoices): array
{
    return ['llamada' => [
        'ocurrio' => '2026-11-20T09:30',
        'canal' => 'llamada',
        'numero' => '3001234567',
        'persona' => 'Juan Pérez',
        'resultado' => 'contesto',
        'acuerdo' => $agreement,
        'facturas' => array_combine($invoices, array_map('strval', $invoices)),
    ]];
}

/**
 * The client file, as the analyst sees it with $query.
 */
function commitmentModal(string $query): Html
{
    return Html::parse((string) test()->actingAs(test()->analyst)->get(test()->file.$query)->assertOk()->getContent());
}

describe('a call agreed as a payment commitment', function () {
    it('opens the commitment form with the balance of its invoices', function () {
        $this->actingAs($this->analyst)
            ->post("{$this->file}/llamadas", agreementCall('compromiso_pago', [$this->invoices[0]->id, $this->invoices[1]->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect("{$this->file}?nuevo-compromiso=1&valor=1734567.50&llamada=".CallRecord::query()->sole()->id)
            ->assertSessionHas('status', 'Llamada registrada. Ahora registra el compromiso de pago.');
    });

    it('leaves the value empty when the call was about no invoice', function () {
        $this->actingAs($this->analyst)
            ->post("{$this->file}/llamadas", agreementCall('compromiso_pago', []))
            ->assertRedirect("{$this->file}?nuevo-compromiso=1&llamada=".CallRecord::query()->sole()->id);
    });

    it('goes back to the file as before for any other agreement', function (string $agreement) {
        $this->actingAs($this->analyst)
            ->post("{$this->file}/llamadas", agreementCall($agreement, [$this->invoices[0]->id]))
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Llamada registrada.');
    })->with(['ninguno', 'envio_soporte']);
});

describe('the new commitment form', function () {
    it('carries the call it was agreed on', function () {
        $call = agreedCall();
        $page = commitmentModal("?nuevo-compromiso=1&valor=1734567.50&llamada={$call->id}");

        expect($page->one('//form[@id="compromiso-nuevo-form"]//input[@type="hidden"][@name="compromiso[llamada]"]')->getAttribute('value'))->toBe((string) $call->id)
            ->and($page->text('//form[@id="compromiso-nuevo-form"]'))->toContain('Acordado en la llamada del 18/11/2026.')
            ->and($page->one('//input[@name="compromiso[valor]"]')->getAttribute('value'))->toBe('1734567.50')
            ->and($page->one('//input[@name="compromiso[fecha]"]')->getAttribute('value'))->toBe('');
    });

    it('ignores a call that is not a pending agreement of this client', function (Closure $callId) {
        $page = commitmentModal('?nuevo-compromiso=1&llamada='.$callId());

        expect($page->count('//input[@name="compromiso[llamada]"]'))->toBe(0)
            ->and($page->text('//form[@id="compromiso-nuevo-form"]'))->not->toContain('Acordado en la llamada');
    })->with([
        'another client' => fn () => agreedCall(['client_id' => test()->other->id])->id,
        'another agreement' => fn () => agreedCall(['agreement' => CallAgreement::SendReceipt])->id,
        'already linked' => fn () => agreedCall(['commitment_id' => 99])->id,
        'missing' => fn () => 987654,
        'not a number' => fn () => agreedCall()->id.'x',
        'zero' => fn () => 0,
    ]);

    it('keeps the call when the form comes back with errors', function () {
        $call = agreedCall();

        $html = (string) $this->actingAs($this->analyst)->followingRedirects()
            ->post("{$this->file}/compromisos", commitmentForm(['fecha' => '', 'llamada' => (string) $call->id]))
            ->getContent();

        expect(Html::parse($html)->one('//input[@name="compromiso[llamada]"]')->getAttribute('value'))->toBe((string) $call->id)
            ->and($html)->toContain('Acordado en la llamada del 18/11/2026.');
        $this->fakes->compromisos->assertNothingCreated();
    });
});

describe('saving the commitment of a call', function () {
    it('links the call to the new commitment', function () {
        $call = agreedCall();

        $this->actingAs($this->analyst)
            ->post("{$this->file}/compromisos", commitmentForm(['llamada' => (string) $call->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Compromiso registrado.');

        $created = $this->fakes->compromisos->all()[0];

        expect($call->refresh()->commitment_id)->toBe($created->id);
    });

    it('never replaces the commitment a call already has', function () {
        $call = agreedCall();
        Log::spy();

        $this->actingAs($this->analyst)->post("{$this->file}/compromisos", commitmentForm(['llamada' => (string) $call->id]));
        $this->actingAs($this->analyst)->post("{$this->file}/compromisos", commitmentForm(['llamada' => (string) $call->id]))
            ->assertRedirect($this->file);

        expect($this->fakes->compromisos->all())->toHaveCount(2)
            ->and($call->refresh()->commitment_id)->toBe($this->fakes->compromisos->all()[0]->id);
        Log::shouldHaveReceived('warning')->once();
    });

    it('creates the commitment but links no call that does not match', function (Closure $callId) {
        $id = $callId();
        Log::spy();

        $this->actingAs($this->analyst)
            ->post("{$this->file}/compromisos", commitmentForm(['llamada' => (string) $id]))
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Compromiso registrado.');

        $this->fakes->compromisos->assertCreated(fn (Commitment $commitment): bool => $commitment->clientId === $this->client->id);
        expect(CallRecord::query()->whereKey($id)->value('commitment_id'))->toBeNull();
        Log::shouldHaveReceived('warning')->once();
    })->with([
        'another client' => fn () => agreedCall(['client_id' => test()->other->id])->id,
        'another agreement' => fn () => agreedCall(['agreement' => CallAgreement::None])->id,
        'missing' => fn () => 987654,
    ]);

    it('still saves the commitment when the link fails', function () {
        $call = agreedCall();
        Log::spy();
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with(strtolower($query), 'update "cartera_calls"') || str_starts_with(strtolower($query), 'update `cartera_calls`')) {
                throw new RuntimeException('The database is gone.');
            }
        });

        $this->actingAs($this->analyst)
            ->post("{$this->file}/compromisos", commitmentForm(['llamada' => (string) $call->id]))
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Compromiso registrado.');

        expect($this->fakes->compromisos->all())->toHaveCount(1)
            ->and($call->refresh()->commitment_id)->toBeNull();
        Log::shouldHaveReceived('warning')->once();
    });

    it('needs a call id that is a whole number above zero', function (mixed $id) {
        $this->actingAs($this->analyst)
            ->post("{$this->file}/compromisos", commitmentForm(['llamada' => $id]))
            ->assertSessionHasErrors('compromiso.llamada');

        $this->fakes->compromisos->assertNothingCreated();
    })->with(['letters' => ['abc'], 'zero' => ['0'], 'negative' => ['-3'], 'decimal' => ['1.5']]);
});

describe('AttachCommitment', function () {
    it('finds the pending call of the client and links it once', function () {
        $call = agreedCall();
        $calls = new AttachCommitment;

        expect($calls->pendingCall($this->client->id, (string) $call->id)?->id)->toBe($call->id)
            ->and($calls->pendingCall($this->other->id, $call->id))->toBeNull()
            ->and($calls->pendingCall($this->client->id, ['1']))->toBeNull()
            ->and($calls->pendingCall($this->client->id, null))->toBeNull()
            ->and($calls->attach($call->id, $this->other->id, 7))->toBeFalse()
            ->and($calls->attach($call->id, $this->client->id, 7))->toBeTrue()
            ->and($calls->attach($call->id, $this->client->id, 8))->toBeFalse()
            ->and($call->refresh()->commitment_id)->toBe(7)
            ->and($calls->pendingCall($this->client->id, $call->id))->toBeNull();
    });
});
