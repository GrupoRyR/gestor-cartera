<?php

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Contact;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Modules\Core\Infrastructure\Portfolio\EloquentCarteraRepository;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\RobotBatches;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    // An analyst sees every client: these tests are about the data, not the scope.
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));
});

it('lists each client with its cached figures, advisor and manager', function () {
    $advisorUser = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $advisorUser, 'carlos.perez@segurex.com');
    TeamUsers::advisor(CompanyCode::SDC, 12, 'Hernán Toro');
    $manager = TeamUsers::member('María Paula Rojas', 'mariapaula.rojas@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);

    $client = CarteraRecords::client('Ferretería El Tornillo S.A.S.', [
        'code' => 'C900123456', 'tax_id' => '900123456-8', 'advisor_code' => 12, 'manager_user_id' => $manager->id,
        'status' => ClientStatus::Overdue, 'balance' => '1234567.89', 'overdue' => '1000000.50', 'max_days_overdue' => 44,
        'last_activity_at' => '2026-10-14 09:30:00',
    ]);

    $summary = CarteraRecords::repository()->paginateClients(new ClientQuery)->items()[0];

    expect($summary)->toBeInstanceOf(ClientSummary::class)
        ->and($summary->id)->toBe($client->id)
        ->and($summary->company)->toBe(CompanyCode::SGX)
        ->and($summary->code)->toBe('C900123456')
        ->and($summary->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($summary->taxId)->toBe('900123456-8')
        ->and($summary->status)->toBe(ClientStatus::Overdue)
        ->and($summary->balance)->toBe(1234567.89)
        ->and($summary->overdue)->toBe(1000000.5)
        ->and($summary->maxDaysOverdue)->toBe(44)
        ->and($summary->advisor)->toEqual(new Advisor(CompanyCode::SGX, 12, 'Carlos Pérez', 'carlos.perez@segurex.com', true, $advisorUser->id))
        ->and($summary->manager)->toEqual(new UserRef($manager->id, 'María Paula Rojas', 'mariapaula.rojas@segurex.com'))
        ->and($summary->lastActivityAt?->toDateTimeString())->toBe('2026-10-14 09:30:00');
});

it('lists a client without advisor record, manager or activity with nulls, and no negative days overdue', function () {
    CarteraRecords::client('Agro Arango', ['advisor_code' => 77, 'max_days_overdue' => -5]);

    $summary = [...CarteraRecords::repository()->allClients(new ClientQuery)][0];

    expect($summary->advisor)->toBeNull()
        ->and($summary->manager)->toBeNull()
        ->and($summary->lastActivityAt)->toBeNull()
        ->and($summary->maxDaysOverdue)->toBe(0);
});

it('keeps the manager of a client after the manager is soft-deleted', function () {
    $manager = TeamUsers::member('Óscar Mejía', 'oscar.mejia@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX], deleted: true);
    CarteraRecords::client('Agro Arango', ['manager_user_id' => $manager->id]);

    expect([...CarteraRecords::repository()->allClients(new ClientQuery)][0]->manager?->name)->toBe('Óscar Mejía');
});

it('searches the name, SAP code and NIT without regard to case', function (string $search, array $expected) {
    CarteraRecords::client('Ferretería El Tornillo S.A.S.', ['code' => 'C900123456', 'tax_id' => '900123456-8']);
    CarteraRecords::client('Distribuciones Arango S.A.S.', ['code' => 'C800197268', 'tax_id' => '800197268-4']);

    expect(CarteraRecords::names(new ClientQuery(search: $search)))->toBe($expected);
})->with([
    'name' => ['tornillo', ['Ferretería El Tornillo S.A.S.']],
    'name in capitals' => ['EL TORNILLO', ['Ferretería El Tornillo S.A.S.']],
    'SAP code' => ['c8001', ['Distribuciones Arango S.A.S.']],
    'NIT' => ['900123', ['Ferretería El Tornillo S.A.S.']],
    'shared fragment' => ['s.a.s', ['Distribuciones Arango S.A.S.', 'Ferretería El Tornillo S.A.S.']],
    'blank' => ['   ', ['Distribuciones Arango S.A.S.', 'Ferretería El Tornillo S.A.S.']],
    'no match' => ['zapata', []],
    'wildcards are literal' => ['%', []],
    'underscore is literal' => ['_', []],
]);

it('filters by status, advisor, overdue balance and inactivity', function () {
    CarteraRecords::client('Agro Arango', ['status' => ClientStatus::Overdue, 'advisor_code' => 12, 'overdue' => '100.00', 'last_activity_at' => '2026-10-01 08:00:00']);
    CarteraRecords::client('Bodega Bedoya', ['status' => ClientStatus::Legal, 'advisor_code' => 20, 'overdue' => '0.00', 'last_activity_at' => null]);
    CarteraRecords::client('Comercial Castaño', ['status' => ClientStatus::Current, 'advisor_code' => 12, 'overdue' => '0.00', 'last_activity_at' => '2026-10-10 08:00:00']);
    CarteraRecords::client('Distribuciones Duque', ['status' => ClientStatus::Committed, 'advisor_code' => null, 'overdue' => '5.00', 'last_activity_at' => '2026-10-12 17:00:00']);

    $since = CarteraRecords::at('2026-10-10 08:00:00');

    expect(CarteraRecords::names(new ClientQuery(statuses: [ClientStatus::Overdue, ClientStatus::Legal])))->toBe(['Agro Arango', 'Bodega Bedoya'])
        ->and(CarteraRecords::names(new ClientQuery(advisorCode: 12)))->toBe(['Agro Arango', 'Comercial Castaño'])
        ->and(CarteraRecords::names(new ClientQuery(onlyOverdue: true)))->toBe(['Agro Arango', 'Distribuciones Duque'])
        ->and(CarteraRecords::names(new ClientQuery(inactiveSince: $since)))->toBe(['Agro Arango', 'Bodega Bedoya'])
        ->and(CarteraRecords::names(new ClientQuery(statuses: [ClientStatus::Overdue], advisorCode: 20)))->toBe([]);
});

it('filters by the companies of the query', function () {
    CarteraRecords::client('Agro Arango');
    CarteraRecords::client('Bodega Bedoya', ['company' => CompanyCode::SDC]);

    expect(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC])))->toBe(['Bodega Bedoya'])
        ->and(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SGX, CompanyCode::SDC])))->toBe(['Agro Arango', 'Bodega Bedoya']);
});

it('sorts the clients and breaks ties by name', function (string $sort, string $direction, array $expected) {
    CarteraRecords::client('Beta', ['balance' => '300.00', 'overdue' => '0.00', 'max_days_overdue' => 0, 'status' => ClientStatus::Overdue, 'last_activity_at' => '2026-10-10 08:00:00']);
    CarteraRecords::client('alfa', ['balance' => '100.00', 'overdue' => '50.00', 'max_days_overdue' => 45, 'status' => ClientStatus::Legal, 'last_activity_at' => null]);
    CarteraRecords::client('Gamma', ['balance' => '300.00', 'overdue' => '50.00', 'max_days_overdue' => -5, 'status' => ClientStatus::Current, 'last_activity_at' => '2026-10-12 08:00:00']);
    CarteraRecords::client('Delta', ['balance' => '200.00', 'overdue' => '0.00', 'max_days_overdue' => 10, 'status' => ClientStatus::Overdue, 'last_activity_at' => null]);

    $query = new ClientQuery(sort: $sort, direction: $direction);

    expect(CarteraRecords::names($query))->toBe($expected)
        ->and(CarteraRecords::namesOf(CarteraRecords::repository()->paginateClients($query)->items()))->toBe($expected);
})->with([
    ['name', 'asc', ['alfa', 'Beta', 'Delta', 'Gamma']],
    ['name', 'desc', ['Gamma', 'Delta', 'Beta', 'alfa']],
    ['balance', 'asc', ['alfa', 'Delta', 'Beta', 'Gamma']],
    ['balance', 'desc', ['Beta', 'Gamma', 'Delta', 'alfa']],
    ['overdue', 'asc', ['Beta', 'Delta', 'alfa', 'Gamma']],
    ['overdue', 'desc', ['alfa', 'Gamma', 'Beta', 'Delta']],
    // Gamma has no invoice past due: it counts as 0 days, like Beta.
    ['days_overdue', 'asc', ['Beta', 'Gamma', 'Delta', 'alfa']],
    ['days_overdue', 'desc', ['alfa', 'Delta', 'Beta', 'Gamma']],
    // Kanban order: Al día, ..., Vencida, ..., Jurídico.
    ['status', 'asc', ['Gamma', 'Beta', 'Delta', 'alfa']],
    ['status', 'desc', ['alfa', 'Beta', 'Delta', 'Gamma']],
    // Clients without activity come first in ascending order.
    ['last_activity', 'asc', ['alfa', 'Delta', 'Beta', 'Gamma']],
    ['last_activity', 'desc', ['Gamma', 'Beta', 'alfa', 'Delta']],
]);

it('breaks ties between equal names by id', function (string $direction) {
    $first = CarteraRecords::client('Agro Arango', ['balance' => '10.00']);
    $second = CarteraRecords::client('Agro Arango', ['balance' => '10.00']);

    $ids = array_map(
        fn (ClientSummary $client): int => $client->id,
        [...CarteraRecords::repository()->allClients(new ClientQuery(sort: 'balance', direction: $direction))],
    );

    expect($ids)->toBe([$first->id, $second->id]);
})->with(['asc', 'desc']);

it('pages the clients with the page of the request', function () {
    foreach (['Agro', 'Bodega', 'Comercial', 'Distribuciones', 'Equipos'] as $name) {
        CarteraRecords::client($name);
    }

    request()->merge(['page' => '2']);
    $page = CarteraRecords::repository()->paginateClients(new ClientQuery, 2);

    expect(CarteraRecords::namesOf($page->items()))->toBe(['Comercial', 'Distribuciones'])
        ->and($page->total())->toBe(5)
        ->and($page->perPage())->toBe(2)
        ->and($page->currentPage())->toBe(2)
        ->and($page->lastPage())->toBe(3);
});

it('pages 25 clients by default', function () {
    ClientRecord::factory()->count(26)->create();

    $page = CarteraRecords::repository()->paginateClients(new ClientQuery);

    expect($page->perPage())->toBe(25)
        ->and($page->items())->toHaveCount(25)
        ->and($page->total())->toBe(26);
});

it('rejects pages of fewer than one client', function (int $perPage) {
    CarteraRecords::repository()->paginateClients(new ClientQuery, $perPage);
})->with([0, -1])->throws(InvalidArgumentException::class);

it('reads every client lazily, in query order, across chunks', function () {
    $rows = [];

    // Many ties on balance, so the order across chunks depends on name and id.
    for ($i = 1; $i <= EloquentCarteraRepository::CHUNK + 2; $i++) {
        $rows[] = [
            'company' => 'SGX', 'code' => "C{$i}", 'name' => 'Cliente '.($i % 7), 'tax_id' => (string) (900000000 + $i),
            'status' => 'al_dia', 'balance' => ($i % 3).'00.00', 'overdue' => '0.00', 'max_days_overdue' => 0,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        ClientRecord::query()->insert($chunk);
    }

    $query = new ClientQuery(sort: 'balance', direction: 'desc');
    $clients = CarteraRecords::repository()->allClients($query);
    $expected = ClientRecord::query()->orderByDesc('balance')->orderBy('name')->orderBy('id')->pluck('id')->all();

    expect($clients)->toBeInstanceOf(Generator::class)
        ->and(array_map(fn (ClientSummary $client): int => $client->id, iterator_to_array($clients)))->toBe($expected);
});

it('returns the client file with its contacts, primary ones first', function () {
    $client = CarteraRecords::client('Ferretería El Tornillo S.A.S.', [
        'credit_limit' => '50000000.00', 'group' => 'Ferreterías', 'city' => 'Medellín', 'payment_terms' => '30 días',
    ]);
    ContactRecord::factory()->for($client, 'client')->create(['name' => 'Laura Martínez', 'email' => 'tesoreria@cliente.example', 'phone' => '+57 300 000 0000', 'position' => 'Tesorería']);
    ContactRecord::factory()->for($client, 'client')->primary()->create(['name' => 'Jorge Henao', 'email' => 'cartera@cliente.example', 'phone' => null, 'position' => null]);
    ContactRecord::factory()->for($client, 'client')->create(['name' => null, 'email' => null, 'phone' => '604 444 4444', 'position' => null]);
    ContactRecord::factory()->create();

    $detail = CarteraRecords::repository()->client($client->id);

    expect($detail?->summary->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($detail?->creditLimit)->toBe(50000000.0)
        ->and($detail?->group)->toBe('Ferreterías')
        ->and($detail?->city)->toBe('Medellín')
        ->and($detail?->paymentTerms)->toBe('30 días')
        ->and($detail?->contacts)->toEqual([
            new Contact('Jorge Henao', 'cartera@cliente.example', null, null, true),
            new Contact('Laura Martínez', 'tesoreria@cliente.example', '+57 300 000 0000', 'Tesorería', false),
            new Contact(null, null, '604 444 4444', null, false),
        ]);
});

it('returns a client file without credit limit or contacts', function () {
    $client = CarteraRecords::client('Agro Arango', ['credit_limit' => null, 'group' => null, 'city' => null, 'payment_terms' => null]);

    $detail = CarteraRecords::repository()->client($client->id);

    expect($detail?->creditLimit)->toBeNull()
        ->and($detail?->group)->toBeNull()
        ->and($detail?->contacts)->toBe([]);
});

it('returns no client file for an id that does not exist', function () {
    expect(CarteraRecords::repository()->client(999_999))->toBeNull()
        ->and(CarteraRecords::repository()->openInvoices(999_999))->toBe([]);
});

it('returns the open invoices of the latest snapshot, oldest due date first', function () {
    $client = CarteraRecords::client('Agro Arango', ['advisor_code' => 12]);
    $other = CarteraRecords::client('Bodega Bedoya');

    $older = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14');
    CarteraRecords::invoice($older, $client, '500.00', 5, ['document_number' => 'OLD-1']);

    // Two loads with the latest cutoff: the last one imported wins.
    $replaced = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15');
    CarteraRecords::invoice($replaced, $client, '700.00', 5, ['document_number' => 'REPLACED-1']);

    // A later cutoff of the other company does not matter.
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-16'), CarteraRecords::client('Casa SDC', ['company' => CompanyCode::SDC]), '1.00');

    $latest = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15');
    $late = CarteraRecords::invoice($latest, $client, '1190000.50', 44, ['document_number' => '104233', 'document_type' => '13', 'amount' => '1190000.50', 'advisor_code' => 12]);
    $credit = CarteraRecords::invoice($latest, $client, '-0.02', 2, ['document_number' => 'NC-9', 'document_type' => '14']);
    $sameDue = CarteraRecords::invoice($latest, $client, '300.00', 2, ['document_number' => '104300']);
    CarteraRecords::invoice($latest, $client, '0.01', 60, ['document_number' => 'CENT']);
    CarteraRecords::invoice($latest, $client, '-0.01', 60, ['document_number' => 'MINUS-CENT']);
    CarteraRecords::invoice($latest, $client, '0.00', 60, ['document_number' => 'PAID']);
    $future = CarteraRecords::invoice($latest, $client, '200.00', -10, ['document_number' => '104400']);
    CarteraRecords::invoice($latest, $other, '900.00', 90, ['document_number' => 'OTHER']);

    $invoices = CarteraRecords::repository()->openInvoices($client->id);

    expect(array_map(fn (Invoice $invoice): string => $invoice->documentNumber, $invoices))->toBe(['104233', 'NC-9', '104300', '104400'])
        ->and(array_map(fn (Invoice $invoice): int => $invoice->id, $invoices))->toBe([$late->id, $credit->id, $sameDue->id, $future->id])
        ->and($invoices[0])->toEqual(new Invoice(
            id: $late->id,
            company: CompanyCode::SGX,
            clientId: $client->id,
            documentNumber: '104233',
            documentType: '13',
            issuedAt: CarteraRecords::at('2026-08-02'),
            dueAt: CarteraRecords::at('2026-09-01'),
            amount: 1190000.5,
            balance: 1190000.5,
            daysOverdue: 44,
            advisorCode: 12,
        ))
        ->and($invoices[1]->balance)->toBe(-0.02);
});

it('returns the open invoices of the latest robot batch only', function () {
    $robot = RobotBatches::install($this->app);
    $robot->receivables(CompanyCode::SGX, '2026-10-14', [RobotBatches::receivable(['documento' => '100001'])]);
    $robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['documento' => '100002', 'fecha_vencimiento' => '2026-10-20']),
        RobotBatches::receivable(['documento' => '100003', 'fecha_vencimiento' => '2026-09-01']),
    ]);

    $client = ClientRecord::query()->where('code', 'C900123456')->sole();
    $invoices = CarteraRecords::repository()->openInvoices($client->id);

    expect(array_map(fn (Invoice $invoice): string => $invoice->documentNumber, $invoices))->toBe(['100003', '100002'])
        ->and(array_map(fn (Invoice $invoice): int => $invoice->daysOverdue, $invoices))->toBe([44, -5])
        ->and($invoices[0]->dueAt->toDateString())->toBe('2026-09-01');
});

it('lists the advisors by name, then company, then code', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    TeamUsers::advisor(CompanyCode::SDC, 7, 'Carlos Pérez');
    TeamUsers::advisor(CompanyCode::SGX, 30, 'Carlos Pérez', $user, 'carlos.perez@segurex.com');
    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', active: false);
    TeamUsers::advisor(CompanyCode::SGX, 5, 'andrés Arango');
    CarteraRecords::switchTo(null);

    $advisors = CarteraRecords::repository()->advisors();

    expect(CarteraRecords::advisors())->toBe(['SGX-5 andrés Arango', 'SGX-12 Carlos Pérez', 'SGX-30 Carlos Pérez', 'SDC-7 Carlos Pérez'])
        ->and($advisors[1])->toEqual(new Advisor(CompanyCode::SGX, 12, 'Carlos Pérez', null, false, null))
        ->and($advisors[2])->toEqual(new Advisor(CompanyCode::SGX, 30, 'Carlos Pérez', 'carlos.perez@segurex.com', true, $user->id));
});
