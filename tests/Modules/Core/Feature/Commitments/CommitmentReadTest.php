<?php

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Reading commitments: find() and paginate() (docs/arquitectura.md §6.5 and
 * §6.13). María Paula, the portfolio analyst, reads them; Agro Arango and
 * Bodega Bedoya are SGX clients of advisors 12 and 20, Granos Giraldo an SDC
 * client of advisor 12.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->people = CommitmentRecords::people();
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Overdue]);
    $this->bodega = CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 20]);
    $this->granos = CarteraRecords::client('Granos Giraldo', ['company' => CompanyCode::SDC, 'advisor_code' => 12]);
    $this->actingAs($this->people['mariaPaula']);
    $this->ids = fn (CommitmentQuery $query, int $perPage = 25): array => CommitmentRecords::idsOf(
        CommitmentRecords::service()->paginate($query, $perPage)->items(),
    );
});

it('finds a commitment with its client, advisor and author', function () {
    $carlos = $this->people['carlos'];
    $stored = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-12', '2500000.50', [
        'status' => CommitmentStatus::Fulfilled,
        'notes' => 'Paga con cheque.',
        'fulfilled_at' => CarteraRecords::at('2026-10-14 16:00:00'),
    ]);
    // The client's advisor changes later: the commitment keeps the one it had.
    $this->agro->update(['advisor_code' => 20, 'name' => 'Agro Arango S.A.S.']);
    $carlos->delete();

    expect(CommitmentRecords::service()->find($stored->id))->toEqual(new Commitment(
        id: $stored->id,
        company: CompanyCode::SGX,
        clientId: $this->agro->id,
        clientName: 'Agro Arango S.A.S.',
        advisorCode: 12,
        amount: 2_500_000.5,
        dueDate: CarteraRecords::at('2026-10-12'),
        status: CommitmentStatus::Fulfilled,
        notes: 'Paga con cheque.',
        createdBy: new UserRef($carlos->id, 'Carlos Pérez', 'carlos.perez@segurex.com'),
        createdAt: CarteraRecords::at('2026-10-15 09:30:00'),
        fulfilledAt: CarteraRecords::at('2026-10-14 16:00:00'),
    ));
});

it('finds nothing for a missing commitment', function () {
    expect(CommitmentRecords::service()->find(999))->toBeNull();
});

it('lists the commitments of the active company by due date', function () {
    $later = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-30');
    $sooner = CommitmentRecords::commitment($this->bodega, $this->people['diana'], '2026-10-20');
    CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-10');

    $page = CommitmentRecords::service()->paginate(new CommitmentQuery);

    expect(CommitmentRecords::idsOf($page->items()))->toBe(CommitmentRecords::ids($sooner, $later))
        ->and($page->total())->toBe(2)
        ->and($page->perPage())->toBe(25)
        ->and($page->items()[0])->toBeInstanceOf(Commitment::class);
});

it('lists the companies of the group view or of the query, limited to the available ones', function () {
    $agro = CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    $granos = CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-10');

    CarteraRecords::switchTo(null);
    $group = ($this->ids)(new CommitmentQuery);
    CarteraRecords::switchTo(CompanyCode::SGX);
    $sdc = ($this->ids)(new CommitmentQuery(companies: [CompanyCode::SDC]));

    $this->actingAs(TeamUsers::member('Sara Gómez', 'sara.gomez@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]));

    expect($group)->toBe(CommitmentRecords::ids($granos, $agro))
        ->and($sdc)->toBe(CommitmentRecords::ids($granos))
        ->and(($this->ids)(new CommitmentQuery(companies: [CompanyCode::SDC])))->toBe([])
        ->and(($this->ids)(new CommitmentQuery(companies: [CompanyCode::SGX, CompanyCode::SDC])))->toBe(CommitmentRecords::ids($agro));
});

it('filters by client, advisor and status', function () {
    $carlos = $this->people['carlos'];
    $pending = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-20');
    $broken = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-10', attributes: ['status' => CommitmentStatus::Broken]);
    $fulfilled = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-05', attributes: ['status' => CommitmentStatus::Fulfilled]);
    $bodega = CommitmentRecords::commitment($this->bodega, $this->people['diana'], '2026-10-21');

    expect(($this->ids)(new CommitmentQuery(clientId: $this->bodega->id)))->toBe(CommitmentRecords::ids($bodega))
        ->and(($this->ids)(new CommitmentQuery(advisorCode: 12)))->toBe(CommitmentRecords::ids($fulfilled, $broken, $pending))
        ->and(($this->ids)(new CommitmentQuery(advisorCode: 99)))->toBe([])
        ->and(($this->ids)(new CommitmentQuery(statuses: [CommitmentStatus::Pending, CommitmentStatus::Broken])))
        ->toBe(CommitmentRecords::ids($broken, $pending, $bodega))
        ->and(($this->ids)(new CommitmentQuery(clientId: $this->agro->id, statuses: [CommitmentStatus::Pending])))
        ->toBe(CommitmentRecords::ids($pending));
});

it('filters by due date, both ends included, whatever the time of day', function () {
    $carlos = $this->people['carlos'];
    $before = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-19');
    $first = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-20');
    $last = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-25');
    $after = CommitmentRecords::commitment($this->agro, $carlos, '2026-10-26');

    expect(($this->ids)(new CommitmentQuery(dueFrom: CarteraRecords::at('2026-10-20 18:00:00'), dueTo: CarteraRecords::at('2026-10-25'))))
        ->toBe(CommitmentRecords::ids($first, $last))
        ->and(($this->ids)(new CommitmentQuery(dueFrom: CarteraRecords::at('2026-10-25'))))->toBe(CommitmentRecords::ids($last, $after))
        ->and(($this->ids)(new CommitmentQuery(dueTo: CarteraRecords::at('2026-10-19 23:59:59'))))->toBe(CommitmentRecords::ids($before));
});

it('sorts by due date, amount or creation, breaking ties by id', function (string $sort, string $direction, array $expected) {
    $carlos = $this->people['carlos'];
    $commitments = [];

    foreach ([['2026-10-22', '300.00', '09:00'], ['2026-10-20', '100.00', '10:00'], ['2026-10-22', '200.00', '08:00'], ['2026-10-20', '100.00', '10:00']] as $i => [$due, $amount, $time]) {
        $this->travelTo(CarteraRecords::at("2026-10-15 {$time}:00"));
        $commitments[$i] = CommitmentRecords::commitment($this->agro, $carlos, $due, $amount);
    }

    $ids = ($this->ids)(new CommitmentQuery(sort: $sort, direction: $direction));

    expect($ids)->toBe(array_map(fn (int $i): int => $commitments[$i]->id, $expected));
})->with([
    'due date, ascending' => ['due_date', 'asc', [1, 3, 0, 2]],
    'due date, descending' => ['due_date', 'desc', [0, 2, 1, 3]],
    'amount, ascending' => ['amount', 'asc', [1, 3, 2, 0]],
    'amount, descending' => ['amount', 'desc', [0, 2, 1, 3]],
    'created, ascending' => ['created_at', 'asc', [2, 0, 1, 3]],
    'created, descending' => ['created_at', 'desc', [1, 3, 0, 2]],
]);

it('pages the commitments with the page of the request', function () {
    $commitments = [];

    foreach (['2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $due) {
        $commitments[] = CommitmentRecords::commitment($this->agro, $this->people['carlos'], $due);
    }

    request()->merge(['page' => '2']);
    $page = CommitmentRecords::service()->paginate(new CommitmentQuery, 2);

    expect(CommitmentRecords::idsOf($page->items()))->toBe(CommitmentRecords::ids($commitments[2], $commitments[3]))
        ->and($page->total())->toBe(5)
        ->and($page->currentPage())->toBe(2)
        ->and($page->lastPage())->toBe(3);
});

it('rejects pages of fewer than one commitment', function (int $perPage) {
    CommitmentRecords::service()->paginate(new CommitmentQuery, $perPage);
})->with([0, -1])->throws(InvalidArgumentException::class);

it('reads a page with the same number of queries for any number of clients and authors', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        CommitmentRecords::service()->paginate(new CommitmentQuery(companies: [CompanyCode::SGX, CompanyCode::SDC]));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-20');
    CommitmentRecords::commitment($this->agro, $this->people['carlos'], '2026-10-21');
    // The first read also loads the user's roles and companies.
    $queries();
    $few = $queries();

    CommitmentRecords::commitment($this->bodega, $this->people['diana'], '2026-10-22');
    CommitmentRecords::commitment($this->granos, $this->people['mariaPaula'], '2026-10-23');
    CommitmentRecords::commitment($this->granos, $this->people['miguel'], '2026-10-24');

    expect($queries())->toBe($few);
});
