<?php

use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Contact;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;

function contractClientSummary(): ClientSummary
{
    return new ClientSummary(
        id: 1,
        company: CompanyCode::SGX,
        code: 'C900123456',
        name: 'Ferretería El Tornillo SAS',
        taxId: '900123456',
        status: ClientStatus::Current,
        balance: 0.0,
        overdue: 0.0,
        maxDaysOverdue: 0,
    );
}

it('lists unique contact emails with primary contacts first', function () {
    $client = new ClientDetail(contractClientSummary(), contacts: [
        new Contact(name: 'Ana', email: 'ana@cliente.com'),
        new Contact(name: 'Sin correo'),
        new Contact(name: 'Tesorería', email: ' Pagos@Cliente.com ', isPrimary: true),
        new Contact(name: 'Vacío', email: '   '),
        new Contact(name: 'Ana otra vez', email: 'ANA@cliente.com'),
        new Contact(name: 'Luis', email: 'luis@cliente.com'),
        new Contact(name: 'Pagos repetido', email: 'pagos@cliente.com'),
    ]);

    expect($client->emails())->toBe(['Pagos@Cliente.com', 'ana@cliente.com', 'luis@cliente.com']);
});

it('has no emails when no contact has one', function () {
    $client = new ClientDetail(contractClientSummary(), contacts: [new Contact(name: 'Ana', phone: '3001234567')]);

    expect($client->emails())->toBe([]);
});

it('builds a client query with sensible defaults', function () {
    $query = new ClientQuery;

    expect($query->search)->toBeNull()
        ->and($query->statuses)->toBe([])
        ->and($query->companies)->toBe([])
        ->and($query->onlyOverdue)->toBeFalse()
        ->and($query->sort)->toBe('name')
        ->and($query->direction)->toBe('asc');
});

it('accepts every documented client sort', function (string $sort) {
    expect((new ClientQuery(sort: $sort, direction: 'desc'))->sort)->toBe($sort);
})->with(ClientQuery::SORTS);

it('documents the client sorts', function () {
    expect(ClientQuery::SORTS)->toBe(['name', 'balance', 'overdue', 'days_overdue', 'status', 'last_activity']);
});

it('rejects an unknown client sort', function () {
    expect(fn () => new ClientQuery(sort: 'nit'))
        ->toThrow(InvalidArgumentException::class, 'nit');
});

it('rejects an unknown client sort direction', function () {
    expect(fn () => new ClientQuery(direction: 'up'))
        ->toThrow(InvalidArgumentException::class, 'up');
});
