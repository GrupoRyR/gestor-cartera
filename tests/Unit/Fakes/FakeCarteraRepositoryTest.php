<?php

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;

/**
 * @param  iterable<int, ClientSummary>  $clients
 * @return list<string>
 */
function clientNames(iterable $clients): array
{
    $names = [];

    foreach ($clients as $client) {
        $names[] = $client->name;
    }

    return $names;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));

    $this->ana = Make::advisor(code: 11, name: 'Ana Ríos');
    $this->beto = Make::advisor(code: 22, name: 'Beto Díaz');

    $this->tornillo = Make::client(
        name: 'Ferretería El Tornillo SAS', taxId: '900111222', advisor: $this->ana,
        status: ClientStatus::Overdue, balance: 3_000_000, overdue: 2_000_000, maxDaysOverdue: 45,
        lastActivityAt: CarbonImmutable::parse('2026-10-01 10:00'),
    );
    $this->andina = Make::client(
        name: 'Distribuidora Andina SA', taxId: '800333444', advisor: $this->beto,
        status: ClientStatus::Current, balance: 5_000_000, overdue: 0, maxDaysOverdue: 0,
    );
    $this->casa = Make::client(
        name: 'Casa Eléctrica Ltda', taxId: '901555666', advisor: $this->ana,
        status: ClientStatus::Committed, balance: 3_000_000, overdue: 500_000, maxDaysOverdue: 95,
        lastActivityAt: CarbonImmutable::parse('2026-10-14 16:00'),
    );
    $this->derivados = Make::client(
        company: CompanyCode::SDC, name: 'Andina de Químicos SAS', taxId: '830777888',
        advisor: Make::advisor(company: CompanyCode::SDC, code: 5, name: 'Sara Gil'),
        status: ClientStatus::Overdue, balance: 1_000_000, overdue: 1_000_000, maxDaysOverdue: 10,
    );

    $this->repository = (new FakeCarteraRepository)
        ->addAdvisor($this->beto, $this->ana)
        ->addClient(
            Make::clientDetail($this->tornillo),
            Make::invoice(clientId: $this->tornillo->id, balance: 1_000_000, daysOverdue: -3),
            Make::invoice(clientId: $this->tornillo->id, balance: 1_500_000, daysOverdue: 12),
            Make::invoice(clientId: $this->tornillo->id, balance: 500_000, daysOverdue: 45),
        )
        ->addClient(
            Make::clientDetail($this->andina),
            Make::invoice(clientId: $this->andina->id, balance: 5_000_000, daysOverdue: 0),
        )
        ->addClient(
            Make::clientDetail($this->casa),
            Make::invoice(clientId: $this->casa->id, balance: 2_500_000, daysOverdue: -20),
            Make::invoice(clientId: $this->casa->id, balance: 300_000, daysOverdue: 61),
            Make::invoice(clientId: $this->casa->id, balance: 200_000, daysOverdue: 95),
        )
        ->addClient(
            Make::clientDetail($this->derivados),
            Make::invoice(company: CompanyCode::SDC, clientId: $this->derivados->id, balance: 1_000_000, daysOverdue: 10),
        );
    $this->repository->cutoffDate = CarbonImmutable::parse('2026-10-14');
});

it('lists every client by name by default', function () {
    expect(clientNames($this->repository->allClients(new ClientQuery)))->toBe([
        'Andina de Químicos SAS',
        'Casa Eléctrica Ltda',
        'Distribuidora Andina SA',
        'Ferretería El Tornillo SAS',
    ]);
});

it('searches by name or NIT without regard to case', function () {
    expect(clientNames($this->repository->allClients(new ClientQuery(search: 'ANDINA'))))
        ->toBe(['Andina de Químicos SAS', 'Distribuidora Andina SA'])
        ->and(clientNames($this->repository->allClients(new ClientQuery(search: '901555'))))
        ->toBe(['Casa Eléctrica Ltda'])
        ->and(clientNames($this->repository->allClients(new ClientQuery(search: '  '))))
        ->toHaveCount(4);
});

it('filters by status, advisor, overdue and inactivity', function () {
    $repository = $this->repository;

    expect(clientNames($repository->allClients(new ClientQuery(statuses: [ClientStatus::Overdue, ClientStatus::Committed]))))
        ->toBe(['Andina de Químicos SAS', 'Casa Eléctrica Ltda', 'Ferretería El Tornillo SAS'])
        ->and(clientNames($repository->allClients(new ClientQuery(advisorCode: 11))))
        ->toBe(['Casa Eléctrica Ltda', 'Ferretería El Tornillo SAS'])
        ->and(clientNames($repository->allClients(new ClientQuery(onlyOverdue: true, companies: [CompanyCode::SGX]))))
        ->toBe(['Casa Eléctrica Ltda', 'Ferretería El Tornillo SAS'])
        ->and(clientNames($repository->allClients(new ClientQuery(inactiveSince: CarbonImmutable::parse('2026-10-10')))))
        ->toBe(['Andina de Químicos SAS', 'Distribuidora Andina SA', 'Ferretería El Tornillo SAS']);
});

it('sorts by any documented column and breaks ties by name', function () {
    $repository = $this->repository;

    expect(clientNames($repository->allClients(new ClientQuery(sort: 'balance', direction: 'desc'))))
        ->toBe(['Distribuidora Andina SA', 'Casa Eléctrica Ltda', 'Ferretería El Tornillo SAS', 'Andina de Químicos SAS'])
        ->and(clientNames($repository->allClients(new ClientQuery(sort: 'status'))))
        ->toBe(['Distribuidora Andina SA', 'Andina de Químicos SAS', 'Ferretería El Tornillo SAS', 'Casa Eléctrica Ltda'])
        ->and(clientNames($repository->allClients(new ClientQuery(sort: 'days_overdue', direction: 'desc'))))
        ->toBe(['Casa Eléctrica Ltda', 'Ferretería El Tornillo SAS', 'Andina de Químicos SAS', 'Distribuidora Andina SA'])
        ->and(clientNames($repository->allClients(new ClientQuery(sort: 'last_activity'))))
        ->toBe(['Andina de Químicos SAS', 'Distribuidora Andina SA', 'Ferretería El Tornillo SAS', 'Casa Eléctrica Ltda']);
});

it('paginates with the current page of the request', function () {
    Paginator::currentPageResolver(fn () => 2);

    $page = $this->repository->paginateClients(new ClientQuery, perPage: 3);

    expect($page->total())->toBe(4)
        ->and($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(3)
        ->and(clientNames($page->items()))->toBe(['Ferretería El Tornillo SAS']);
});

it('rejects pages without rows', function () {
    expect(fn () => $this->repository->paginateClients(new ClientQuery, perPage: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('returns the client file and its open invoices by due date', function () {
    $detail = $this->repository->client($this->tornillo->id);
    $invoices = $this->repository->openInvoices($this->tornillo->id);

    expect($detail?->summary)->toBe($this->tornillo)
        ->and(array_map(fn (Invoice $invoice): int => $invoice->daysOverdue, $invoices))->toBe([45, 12, -3])
        ->and($this->repository->client(999_999))->toBeNull()
        ->and($this->repository->openInvoices(999_999))->toBe([]);
});

it('computes totals and aging from the open invoices of matching clients', function () {
    $totals = $this->repository->totals(new ClientQuery(companies: [CompanyCode::SGX]));

    expect($totals)->toEqual(new PortfolioTotals(
        cutoffDate: CarbonImmutable::parse('2026-10-14'),
        total: 11_000_000.0,
        overdue: 2_500_000.0,
        clientCount: 3,
        overdueClientCount: 2,
        aging: new AgingBuckets(
            notDue: 8_500_000.0,
            days1To30: 1_500_000.0,
            days31To60: 500_000.0,
            days61To90: 300_000.0,
            over90: 200_000.0,
        ),
    ));
});

it('has empty totals without a snapshot', function () {
    expect((new FakeCarteraRepository)->totals())->toEqual(PortfolioTotals::empty());
});

it('limits results to the companies in scope', function () {
    $current = new FakeCurrentCompany(active: CompanyCode::SGX, available: [CompanyCode::SGX]);
    $repository = new FakeCarteraRepository($current);
    $repository->addClient(Make::clientDetail($this->tornillo))->addClient(Make::clientDetail($this->derivados));

    expect(clientNames($repository->allClients(new ClientQuery)))->toBe(['Ferretería El Tornillo SAS'])
        ->and($repository->client($this->derivados->id))->toBeNull()
        ->and(clientNames($repository->allClients(new ClientQuery(companies: [CompanyCode::SDC]))))->toBe([]);
});

it('lets explicit companies reach any available company', function () {
    $current = new FakeCurrentCompany(active: CompanyCode::SGX);
    $repository = new FakeCarteraRepository($current);
    $repository->addClient(Make::clientDetail($this->tornillo))->addClient(Make::clientDetail($this->derivados));

    expect(clientNames($repository->allClients(new ClientQuery(companies: [CompanyCode::SDC]))))
        ->toBe(['Andina de Químicos SAS']);

    $current->switchTo(null);

    expect(clientNames($repository->allClients(new ClientQuery)))->toHaveCount(2);
});

it('emulates the advisor scope so isolation tests can be written now', function () {
    $repository = $this->repository->restrictToAdvisor(22);

    expect(clientNames($repository->allClients(new ClientQuery)))->toBe(['Distribuidora Andina SA'])
        ->and($repository->client($this->tornillo->id))->toBeNull()
        ->and($repository->openInvoices($this->tornillo->id))->toBe([])
        ->and($repository->totals()->total)->toBe(5_000_000.0)
        ->and(array_map(fn ($advisor) => $advisor->code, $repository->advisors()))->toBe([22]);
});

it('ignores the user scope as the system and sees later changes', function () {
    $repository = $this->repository->restrictToAdvisor(22);
    $system = $repository->asSystem();

    $late = Make::client(name: 'Zapatería Nueva', advisor: $this->ana);
    $repository->addClient(Make::clientDetail($late));

    expect(clientNames($system->allClients(new ClientQuery)))->toHaveCount(5)
        ->and($system->client($late->id)?->summary->name)->toBe('Zapatería Nueva')
        ->and(clientNames($repository->allClients(new ClientQuery)))->toBe(['Distribuidora Andina SA']);
});

it('lists advisors by name', function () {
    expect(array_map(fn ($advisor) => $advisor->name, $this->repository->advisors()))
        ->toBe(['Ana Ríos', 'Beto Díaz']);
});

it('returns the stored history inside the range in ascending order', function () {
    $this->repository->addHistory(
        new PortfolioTotals(CarbonImmutable::parse('2026-10-10'), 10, 1, 1, 1, new AgingBuckets),
        new PortfolioTotals(CarbonImmutable::parse('2026-09-30'), 30, 3, 1, 1, new AgingBuckets),
        new PortfolioTotals(CarbonImmutable::parse('2026-10-01'), 20, 2, 1, 1, new AgingBuckets),
    );

    $history = $this->repository->history(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10'));

    expect(array_map(fn (PortfolioTotals $totals): float => $totals->total, $history))->toBe([20.0, 10.0]);
});

it('rejects a history range that ends before it starts', function () {
    expect(fn () => $this->repository->history(CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-01')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses invoices of another client', function () {
    expect(fn () => (new FakeCarteraRepository)->addClient(
        Make::clientDetail($this->andina),
        Make::invoice(clientId: $this->tornillo->id),
    ))->toThrow(InvalidArgumentException::class);
});
