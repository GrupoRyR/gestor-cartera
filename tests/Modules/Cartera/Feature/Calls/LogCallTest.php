<?php

use App\Models\User;
use App\Modules\Cartera\Application\Calls\LogCall;
use App\Modules\Cartera\Application\Calls\NewCall;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use App\Modules\Cartera\Infrastructure\Persistence\CallInvoiceRecord;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));

    $this->fakes = ContractFakes::install($this->app);
    $this->client = Make::client(name: 'Ferretería El Tornillo SAS');
    $this->invoice = Make::invoice(
        clientId: $this->client->id,
        documentNumber: 'FV-123',
        dueAt: CarbonImmutable::parse('2026-10-15'),
        balance: 1_234_567.0,
    );
    $this->notDue = Make::invoice(
        clientId: $this->client->id,
        documentNumber: 'FV-200',
        dueAt: CarbonImmutable::parse('2026-11-30'),
        balance: 500_000.0,
    );
    $this->fakes->cartera->addClient(Make::clientDetail($this->client), $this->invoice, $this->notDue);
});

/**
 * A call the analyst could log, with the values each test changes.
 *
 * @param  list<int>  $invoiceIds
 */
function callInput(
    int $clientId,
    CallChannel $channel = CallChannel::Call,
    string $phone = '3001234567',
    ?string $contactName = 'Juan Pérez',
    CallOutcome $outcome = CallOutcome::Answered,
    ?string $notes = null,
    CallAgreement $agreement = CallAgreement::None,
    ?CarbonImmutable $followUpOn = null,
    ?CarbonImmutable $occurredAt = null,
    array $invoiceIds = [],
): NewCall {
    return new NewCall($clientId, $channel, $phone, $contactName, $outcome, $notes, $agreement, $followUpOn, $occurredAt, $invoiceIds);
}

function logCall(NewCall $call, User $user): CallRecord
{
    return app(LogCall::class)->log($call, $user);
}

/**
 * The validation errors log() throws, by field.
 *
 * @return array<string, list<string>>
 */
function callErrors(NewCall $call, ?User $user = null): array
{
    try {
        logCall($call, $user ?? CarteraUsers::analyst());
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('The call was accepted.');
}

function sdcClient(ContractFakes $fakes): ClientSummary
{
    $client = Make::client(company: CompanyCode::SDC, name: 'Agro Arango SAS');
    $fakes->cartera->addClient(Make::clientDetail($client));

    return $client;
}

function expectNothingStored(ContractFakes $fakes): void
{
    expect(CallRecord::query()->count())->toBe(0)
        ->and(CallInvoiceRecord::query()->count())->toBe(0);

    $fakes->gestiones->assertNothingLogged();
}

it('stores the call with its contact and invoices and logs it in the client log', function () {
    $user = CarteraUsers::analyst();

    $call = logCall(callInput(
        $this->client->id,
        channel: CallChannel::WhatsappCall,
        contactName: '  Juan Pérez ',
        notes: "  Pagará la otra semana.\n",
        agreement: CallAgreement::SendReceipt,
        invoiceIds: [$this->invoice->id],
    ), $user);

    $record = CallRecord::query()->with('invoices')->sole();
    $entry = $this->fakes->gestiones->logged();

    expect($entry)->toHaveCount(1);
    $entry = $entry[0];

    expect($record->id)->toBe($call->id)
        ->and($record->company)->toBe(CompanyCode::SGX)
        ->and($record->client_id)->toBe($this->client->id)
        ->and($record->user_id)->toBe($user->id)
        ->and($record->occurred_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($record->channel)->toBe(CallChannel::WhatsappCall)
        ->and($record->phone)->toBe('3001234567')
        ->and($record->contact_name)->toBe('Juan Pérez')
        ->and($record->outcome)->toBe(CallOutcome::Answered)
        ->and($record->notes)->toBe('Pagará la otra semana.')
        ->and($record->agreement)->toBe(CallAgreement::SendReceipt)
        ->and($record->commitment_id)->toBeNull()
        ->and($record->follow_up_on)->toBeNull()
        ->and($record->follow_up_closed_at)->toBeNull()
        ->and($record->activity_id)->toBe($entry->id);

    $invoice = $record->invoices->sole();

    expect($invoice->invoice_id)->toBe($this->invoice->id)
        ->and($invoice->document_number)->toBe('FV-123')
        ->and($invoice->document_type)->toBe('Factura')
        ->and($invoice->due_at->format('Y-m-d'))->toBe('2026-10-15')
        ->and($invoice->balance)->toBe('1234567.00');

    expect($entry)->toBeInstanceOf(Activity::class)
        ->and($entry->clientId)->toBe($this->client->id)
        ->and($entry->type)->toBe(ActivityType::Call)
        ->and($entry->summary)->toBe('Llamada de WhatsApp a Juan Pérez (3001234567)')
        ->and($entry->details)->toBe("Pagará la otra semana.\nFactura FV-123, vence 15/10/2026, saldo ".Money::format(1_234_567))
        ->and($entry->result)->toBe('Contestó · Envío de soporte')
        ->and($entry->occurredAt->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($entry->metadata)->toBe([
            'call_id' => $record->id,
            'channel' => 'llamada_whatsapp',
            'outcome' => 'contesto',
            'agreement' => 'envio_soporte',
        ]);
});

it('lists every chosen invoice, the oldest due first, once each', function () {
    logCall(callInput($this->client->id, invoiceIds: [$this->notDue->id, $this->invoice->id, $this->notDue->id]), CarteraUsers::analyst());

    expect(CallInvoiceRecord::query()->orderBy('id')->pluck('document_number')->all())->toBe(['FV-123', 'FV-200'])
        ->and($this->fakes->gestiones->logged()[0]->details)->toBe(implode("\n", [
            'Factura FV-123, vence 15/10/2026, saldo '.Money::format(1_234_567),
            'Factura FV-200, vence 30/11/2026, saldo '.Money::format(500_000),
        ]));
});

it('names only the phone when there is no contact', function (CallChannel $channel, ?string $contact, string $summary) {
    $call = logCall(callInput($this->client->id, channel: $channel, contactName: $contact, outcome: CallOutcome::NoAnswer), CarteraUsers::manager());

    $entry = $this->fakes->gestiones->logged()[0];

    expect($call->contact_name)->toBeNull()
        ->and($entry->summary)->toBe($summary)
        ->and($entry->details)->toBeNull()
        ->and($entry->result)->toBe('No contestó');
})->with([
    'call' => [CallChannel::Call, null, 'Llamada al 3001234567'],
    'whatsapp message' => [CallChannel::WhatsappMessage, '   ', 'Mensaje de WhatsApp al 3001234567'],
]);

it('keeps the day to call back', function () {
    $call = logCall(callInput($this->client->id, outcome: CallOutcome::CallBack, followUpOn: CarbonImmutable::parse('2026-11-20')), CarteraUsers::analyst());

    expect($call->follow_up_on?->format('Y-m-d'))->toBe('2026-11-20')
        ->and($this->fakes->gestiones->logged()[0]->result)->toBe('Pidió volver a llamar');
});

it('takes a call that happened earlier, up to 30 days back', function () {
    $occurredAt = CarbonImmutable::parse('2026-10-21 00:00:00', 'America/Bogota');

    $call = logCall(callInput($this->client->id, occurredAt: $occurredAt), CarteraUsers::analyst());

    expect($call->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($this->fakes->gestiones->logged()[0]->occurredAt->equalTo($occurredAt))->toBeTrue();
});

it('is for those who manage the portfolio only', function (?Role $role) {
    expect(fn () => logCall(callInput($this->client->id), CarteraUsers::create($role)))
        ->toThrow(AuthorizationException::class);

    expectNothingStored($this->fakes);
})->with([
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'treasury' => [Role::Treasury],
    'no role' => [null],
]);

it('refuses those who may only look', function () {
    expect(fn () => logCall(callInput($this->client->id), CarteraUsers::viewer()))->toThrow(AuthorizationException::class);

    expectNothingStored($this->fakes);
});

it('does not find a client out of scope', function () {
    $client = sdcClient($this->fakes);

    expect(fn () => logCall(callInput($client->id), CarteraUsers::analyst()))->toThrow(RecordNotFound::class);

    expectNothingStored($this->fakes);
});

it('lets the SDC analyst agree a payment commitment', function () {
    $client = sdcClient($this->fakes);
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

    $call = logCall(callInput($client->id, agreement: CallAgreement::PaymentCommitment), CarteraUsers::analyst());

    expect($call->agreement)->toBe(CallAgreement::PaymentCommitment)
        ->and($call->company)->toBe(CompanyCode::SDC)
        ->and($this->fakes->gestiones->logged()[0]->result)->toBe('Contestó · Compromiso de pago');
});

it('refuses a payment commitment in SGX, where the advisor writes them', function () {
    expect(fn () => logCall(callInput($this->client->id, agreement: CallAgreement::PaymentCommitment), CarteraUsers::analyst()))
        ->toThrow(AuthorizationException::class);

    expectNothingStored($this->fakes);
});

it('refuses a payment commitment to the portfolio manager', function () {
    $client = sdcClient($this->fakes);
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

    expect(fn () => logCall(callInput($client->id, agreement: CallAgreement::PaymentCommitment), CarteraUsers::manager()))
        ->toThrow(AuthorizationException::class);

    expectNothingStored($this->fakes);
});

it('takes an agreement only when the client answered', function (CallOutcome $outcome) {
    $errors = callErrors(callInput($this->client->id, outcome: $outcome, agreement: CallAgreement::SendReceipt, followUpOn: CarbonImmutable::parse('2026-11-21')));

    expect($errors)->toBe(['acuerdo' => ['Registra un acuerdo solo si el cliente contestó.']]);
    expectNothingStored($this->fakes);
})->with([CallOutcome::NoAnswer, CallOutcome::Voicemail, CallOutcome::WrongNumber, CallOutcome::CallBack]);

it('asks when to call back', function () {
    expect(callErrors(callInput($this->client->id, outcome: CallOutcome::CallBack)))
        ->toBe(['seguimiento' => ['Elige el día en que vas a volver a llamar.']]);

    expectNothingStored($this->fakes);
});

it('refuses a follow-up in the past', function () {
    expect(callErrors(callInput($this->client->id, outcome: CallOutcome::CallBack, followUpOn: CarbonImmutable::parse('2026-11-19'))))
        ->toBe(['seguimiento' => ['Elige hoy o un día posterior para volver a llamar.']]);

    expectNothingStored($this->fakes);
});

it('refuses a call in the future or more than 30 days back', function (string $occurredAt, string $message) {
    expect(callErrors(callInput($this->client->id, occurredAt: CarbonImmutable::parse($occurredAt, 'America/Bogota'))))
        ->toBe(['ocurrio' => [$message]]);

    expectNothingStored($this->fakes);
})->with([
    'a minute ahead' => ['2026-11-20 10:01:00', 'La llamada no puede tener una fecha futura.'],
    '31 days back' => ['2026-10-20 23:59:00', 'Solo puedes registrar llamadas de los últimos 30 días.'],
]);

it('refuses an invoice that is not open for the client', function () {
    $other = Make::client(name: 'Otro cliente SAS');
    $foreign = Make::invoice(clientId: $other->id);
    $this->fakes->cartera->addClient(Make::clientDetail($other), $foreign);

    foreach ([999_999, $foreign->id] as $invoiceId) {
        expect(callErrors(callInput($this->client->id, invoiceIds: [$this->invoice->id, $invoiceId])))
            ->toBe(['facturas' => ['Alguna de las facturas ya no está abierta. Vuelve a cargar la ficha.']]);
    }

    expectNothingStored($this->fakes);
});

it('closes the pending follow-ups of the client and resolves its notice', function () {
    $user = CarteraUsers::analyst();
    $closedAt = CarbonImmutable::parse('2026-11-18 09:00:00');

    $pending = CallRecord::factory()->forClient($this->client->id)->followUpOn(CarbonImmutable::parse('2026-11-19'))->create();
    $done = CallRecord::factory()->forClient($this->client->id)->followUpOn(CarbonImmutable::parse('2026-11-17'))
        ->create(['follow_up_closed_at' => $closedAt]);
    $otherClient = CallRecord::factory()->forClient($this->client->id + 1_000)->followUpOn(CarbonImmutable::parse('2026-11-19'))->create();

    $call = logCall(callInput($this->client->id, outcome: CallOutcome::CallBack, followUpOn: CarbonImmutable::parse('2026-11-25')), $user);

    expect($pending->refresh()->follow_up_closed_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($done->refresh()->follow_up_closed_at?->equalTo($closedAt))->toBeTrue()
        ->and($otherClient->refresh()->follow_up_closed_at)->toBeNull()
        ->and($call->refresh()->follow_up_closed_at)->toBeNull();

    $this->fakes->avisos->assertResolved("cartera.call.follow_up:{$this->client->id}");
});

it('stores the time of the call in the app timezone, whatever zone it came in', function () {
    $call = logCall(callInput($this->client->id, occurredAt: CarbonImmutable::parse('2026-11-20 14:00:00', 'UTC')), CarteraUsers::analyst());

    expect((string) DB::table('cartera_calls')->where('id', $call->id)->value('occurred_at'))->toStartWith('2026-11-20 09:00:00')
        ->and($call->refresh()->occurred_at->equalTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'America/Bogota')))->toBeTrue();
});

it('leaves open the follow-up of a later call when the new call happened before it', function () {
    $later = CallRecord::factory()->forClient($this->client->id)->followUpOn(CarbonImmutable::parse('2026-11-24'))
        ->create(['occurred_at' => CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Bogota')]);
    $earlier = CallRecord::factory()->forClient($this->client->id)->followUpOn(CarbonImmutable::parse('2026-11-19'))
        ->create(['occurred_at' => CarbonImmutable::parse('2026-11-05 09:00:00', 'America/Bogota')]);

    logCall(callInput($this->client->id, occurredAt: CarbonImmutable::parse('2026-11-10 11:00:00', 'America/Bogota')), CarteraUsers::analyst());

    expect($earlier->refresh()->follow_up_closed_at)->not->toBeNull()
        ->and($later->refresh()->follow_up_closed_at)->toBeNull()
        // The later follow-up still needs its bell notice.
        ->and($this->fakes->avisos->resolvedKeys())->toBe([]);
});

it('writes nothing when the log entry fails', function () {
    $pending = CallRecord::factory()->forClient($this->client->id)->followUpOn(CarbonImmutable::parse('2026-11-19'))->create();

    $this->app->instance(GestionLogger::class, new class implements GestionLogger
    {
        public function log(NewActivity $activity): Activity
        {
            throw new RuntimeException('The log is down.');
        }

        public function forClient(int $clientId, int $limit = 50): array
        {
            return [];
        }

        public function recent(int $limit = 20): array
        {
            return [];
        }
    });

    expect(fn () => logCall(callInput($this->client->id, invoiceIds: [$this->invoice->id]), CarteraUsers::analyst()))
        ->toThrow(RuntimeException::class, 'The log is down.');

    expect(CallRecord::query()->whereKeyNot($pending->id)->count())->toBe(0)
        ->and(CallInvoiceRecord::query()->count())->toBe(0)
        ->and($pending->refresh()->follow_up_closed_at)->toBeNull()
        ->and($this->fakes->avisos->resolvedKeys())->toBe([]);
});
