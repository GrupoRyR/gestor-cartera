<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Infrastructure\Ingest\ImportContacts;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Core\Support\RobotBatches;

beforeEach(function () {
    $this->robot = RobotBatches::install($this->app);
});

/**
 * Every contact as [company, client code, name, email, phone, position, is_primary, source],
 * by company, client code, primary first, then e-mail.
 *
 * @return list<array{string, string, string|null, string|null, string|null, string|null, bool, string}>
 */
function contactRows(): array
{
    return ContactRecord::query()
        ->join('clients', 'clients.id', '=', 'contacts.client_id')
        ->orderByDesc('clients.company')->orderBy('clients.code')
        ->orderByDesc('contacts.is_primary')->orderBy('contacts.email')
        ->get(['contacts.*', 'clients.company as client_company', 'clients.code as client_code'])
        ->map(fn (ContactRecord $contact): array => [
            $contact->getAttribute('client_company'), $contact->getAttribute('client_code'), $contact->name,
            $contact->email, $contact->phone, $contact->position, $contact->is_primary, $contact->source,
        ])
        ->all();
}

function contactsClient(string $code, CompanyCode $company = CompanyCode::SGX): ClientRecord
{
    return ClientRecord::factory()->create(['company' => $company, 'code' => $code]);
}

it('imports the active SAP contacts of each client with clean, unique e-mails', function () {
    contactsClient('C900123456');
    contactsClient('C800765432');

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', [
        // The general row of the client: no person, its own e-mail and phones.
        RobotBatches::contact(['contacto_id' => null, 'nombre' => null, 'cargo' => null, 'correo' => ' Cartera@Example.com ',
            'telefono' => null, 'celular' => '300 000 0000', 'principal' => 'N']),
        // Two e-mails in one field, one of them repeated by the general row, and an invalid one.
        RobotBatches::contact(['correo' => 'Gloria.Henao@example.com; cartera@example.com, no-es-correo']),
        RobotBatches::contact(['contacto_id' => 2, 'nombre' => 'Jorge Toro', 'cargo' => null, 'correo' => 'GLORIA.HENAO@example.com',
            'telefono' => null, 'celular' => null, 'principal' => 'n']),
        // An inactive person never receives the collection e-mails.
        RobotBatches::contact(['contacto_id' => 3, 'nombre' => 'Hernán Sierra', 'correo' => 'hernan@example.com', 'principal' => 'N', 'activo' => 'N']),
        // A person with no valid e-mail is kept for the calls.
        RobotBatches::contact(['codigo_sn' => 'C800765432', 'contacto_id' => 7, 'nombre' => 'Diana Mejía', 'cargo' => 'Compras',
            'correo' => 'diana@', 'telefono' => '604 555 0000', 'celular' => null, 'principal' => ' y ']),
    ]);

    expect(contactRows())->toBe([
        ['SGX', 'C800765432', 'Diana Mejía', null, '604 555 0000', 'Compras', true, 'sap'],
        ['SGX', 'C900123456', 'Gloria Henao', 'cartera@example.com', '604 444 1234 / 300 123 4567', 'Tesorería', true, 'sap'],
        ['SGX', 'C900123456', 'Gloria Henao', 'gloria.henao@example.com', '604 444 1234 / 300 123 4567', 'Tesorería', true, 'sap'],
        // The general row lost its e-mail to the primary contact, but keeps its phone for the calls.
        ['SGX', 'C900123456', null, null, '300 000 0000', null, false, 'sap'],
    ]);
});

it('replaces only the SAP contacts of the company and keeps the ones made on the platform', function () {
    $tornillo = contactsClient('C900123456');
    $derivados = contactsClient('C900123456', CompanyCode::SDC);
    ContactRecord::factory()->for($tornillo, 'client')->create(['email' => 'viejo@example.com']);
    ContactRecord::factory()->for($tornillo, 'client')->create([
        'name' => 'Marta Uribe', 'email' => 'marta@example.com', 'phone' => null, 'position' => null, 'source' => 'platform',
    ]);
    ContactRecord::factory()->for($derivados, 'client')->create([
        'name' => 'Óscar Toro', 'email' => 'oscar@example.com', 'phone' => null, 'position' => null,
    ]);

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', [
        RobotBatches::contact(['telefono' => null, 'celular' => null]),
    ]);

    expect(contactRows())->toBe([
        ['SGX', 'C900123456', 'Gloria Henao', 'gloria.henao@example.com', null, 'Tesorería', true, 'sap'],
        ['SGX', 'C900123456', 'Marta Uribe', 'marta@example.com', null, null, false, 'platform'],
        ['SDC', 'C900123456', 'Óscar Toro', 'oscar@example.com', null, null, false, 'sap'],
    ]);
});

it('gives the same result when the same contacts arrive again', function () {
    contactsClient('C900123456');
    $rows = [
        RobotBatches::contact(),
        RobotBatches::contact(['contacto_id' => 2, 'nombre' => 'Jorge Toro', 'correo' => 'jorge@example.com', 'principal' => 'N']),
    ];

    $load = $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', $rows);
    $first = contactRows();
    event(new BatchAccepted($load));

    expect(contactRows())->toBe($first)
        ->and($first)->toHaveCount(2);
});

it('skips and logs the rows of unknown or removed clients without failing the batch', function () {
    $removed = contactsClient('C800765432');
    ContactRecord::factory()->for($removed, 'client')->create(['email' => 'sigue@example.com']);
    $removed->delete();
    contactsClient('C900123456');
    Log::spy();

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', [
        RobotBatches::contact(['codigo_sn' => 'C111111111']),
        RobotBatches::contact(['codigo_sn' => 'C800765432']),
        RobotBatches::contact(),
    ]);

    expect(ContactRecord::query()->pluck('email')->sort()->values()->all())
        ->toBe(['gloria.henao@example.com', 'sigue@example.com']);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['company'] === 'SGX'
        && $context['skipped_rows'] === 2
        && $context['client_codes'] === ['C111111111', 'C800765432']);
});

it('leaves the contacts untouched when a batch has no row', function () {
    $client = contactsClient('C900123456');
    ContactRecord::factory()->for($client, 'client')->create();

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', []);

    expect(ContactRecord::query()->count())->toBe(1);
});

it('ignores a batch that is no longer the latest of the company', function () {
    contactsClient('C900123456');
    $old = $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-03', [RobotBatches::contact(['correo' => 'viejo@example.com'])]);
    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', [RobotBatches::contact()]);

    event(new BatchAccepted($old));

    expect(ContactRecord::query()->pluck('email')->all())->toBe(['gloria.henao@example.com']);
});

it('ignores the batches of other datasets', function () {
    $client = contactsClient('C900123456');
    ContactRecord::factory()->for($client, 'client')->create(['email' => 'sigue@example.com']);

    $this->robot->send(Dataset::Sales, CompanyCode::SGX, '2026-11-10', [RobotBatches::contact()]);

    expect(ContactRecord::query()->pluck('email')->all())->toBe(['sigue@example.com']);
});

it('queues the import of contactos batches only', function () {
    Queue::fake();

    $this->robot->send(Dataset::Sales, CompanyCode::SGX, '2026-11-10', []);

    Queue::assertNothingPushed();

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-11-10', [RobotBatches::contact()]);

    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportContacts::class);
});
