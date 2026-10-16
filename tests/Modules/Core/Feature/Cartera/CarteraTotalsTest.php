<?php

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));
});

it('adds up the latest snapshot of the active company', function () {
    $agro = CarteraRecords::client('Agro Arango');
    $bodega = CarteraRecords::client('Bodega Bedoya');
    $older = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14');
    $latest = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15');

    CarteraRecords::invoice($older, $agro, '999.00', 10);
    CarteraRecords::invoice($latest, $agro, '1000.00', 10);
    CarteraRecords::invoice($latest, $agro, '500.50', -3);
    CarteraRecords::invoice($latest, $bodega, '250.25');
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-15'), CarteraRecords::client('Casa SDC', ['company' => CompanyCode::SDC]), '7000.00', 40);

    $totals = CarteraRecords::repository()->totals();

    expect($totals->cutoffDate?->toDateString())->toBe('2026-10-15')
        ->and($totals->total)->toBe(1750.75)
        ->and($totals->overdue)->toBe(1000.0)
        ->and($totals->clientCount)->toBe(2)
        ->and($totals->overdueClientCount)->toBe(1)
        ->and($totals->aging)->toEqual(new AgingBuckets(notDue: 750.75, days1To30: 1000.0));
});

it('uses the last load imported when two share the latest cutoff date', function () {
    $agro = CarteraRecords::client('Agro Arango');
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15'), $agro, '100.00');
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15'), $agro, '300.00');

    expect(CarteraRecords::repository()->totals()->total)->toBe(300.0);
});

it('combines the latest snapshot of each company in the group view, under the oldest cutoff', function () {
    CarteraRecords::switchTo(null);
    $sgx = CarteraRecords::client('Agro Arango');
    $sdc = CarteraRecords::client('Casa SDC', ['company' => CompanyCode::SDC]);

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15'), $sgx, '1000.00', 5);
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-12'), $sdc, '9999.00', 5);
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-13'), $sdc, '2000.00', 70);

    $totals = CarteraRecords::repository()->totals();

    expect($totals->cutoffDate?->toDateString())->toBe('2026-10-13')
        ->and($totals->total)->toBe(3000.0)
        ->and($totals->overdue)->toBe(3000.0)
        ->and($totals->clientCount)->toBe(2)
        ->and($totals->overdueClientCount)->toBe(2)
        ->and($totals->aging)->toEqual(new AgingBuckets(days1To30: 1000.0, days61To90: 2000.0));
});

it('puts each invoice in the aging bucket of its days overdue', function () {
    $client = CarteraRecords::client('Agro Arango');
    $snapshot = CarteraRecords::snapshot();

    // Powers of two, so each bucket shows which invoices it got.
    foreach ([-3 => '1.00', 0 => '2.00', 1 => '4.00', 30 => '8.00', 31 => '16.00', 60 => '32.00', 61 => '64.00', 90 => '128.00', 91 => '256.00'] as $days => $balance) {
        CarteraRecords::invoice($snapshot, $client, $balance, $days);
    }

    $totals = CarteraRecords::repository()->totals();

    expect($totals->aging)->toEqual(new AgingBuckets(notDue: 3.0, days1To30: 12.0, days31To60: 48.0, days61To90: 192.0, over90: 256.0))
        ->and($totals->total)->toBe(511.0)
        ->and($totals->overdue)->toBe(508.0)
        ->and($totals->overdueClientCount)->toBe(1);
});

it('subtracts credit notes and counts a client as overdue only when its overdue balance is positive', function () {
    $snapshot = CarteraRecords::snapshot();
    $settled = CarteraRecords::client('Agro Arango');
    $owing = CarteraRecords::client('Bodega Bedoya');

    CarteraRecords::invoice($snapshot, $settled, '1000.00', 10);
    CarteraRecords::invoice($snapshot, $settled, '-1000.00', 5, ['document_type' => '14']);
    CarteraRecords::invoice($snapshot, $owing, '600.00', 45);
    CarteraRecords::invoice($snapshot, $owing, '-100.00', 0, ['document_type' => '14']);

    $totals = CarteraRecords::repository()->totals();

    expect($totals->total)->toBe(500.0)
        ->and($totals->overdue)->toBe(600.0)
        ->and($totals->clientCount)->toBe(2)
        ->and($totals->overdueClientCount)->toBe(1)
        ->and($totals->aging)->toEqual(new AgingBuckets(notDue: -100.0, days1To30: 0.0, days31To60: 600.0));
});

it('keeps the sums exact', function () {
    $client = CarteraRecords::client('Agro Arango');
    $snapshot = CarteraRecords::snapshot();

    foreach (['0.10', '0.20', '0.40'] as $balance) {
        CarteraRecords::invoice($snapshot, $client, $balance, 3);
    }

    $totals = CarteraRecords::repository()->totals();

    expect($totals->total)->toBe(0.7)
        ->and($totals->overdue)->toBe(0.7);
});

it('applies the filters of the query', function () {
    $snapshot = CarteraRecords::snapshot();
    $agro = CarteraRecords::client('Agro Arango', ['status' => ClientStatus::Overdue, 'advisor_code' => 12, 'overdue' => '1000.00']);
    $bodega = CarteraRecords::client('Bodega Bedoya', ['status' => ClientStatus::Current, 'advisor_code' => 20]);
    $comercial = CarteraRecords::client('Comercial Castaño', ['status' => ClientStatus::Legal, 'advisor_code' => 12, 'overdue' => '50.00']);

    CarteraRecords::invoice($snapshot, $agro, '1000.00', 10);
    CarteraRecords::invoice($snapshot, $bodega, '300.00');
    CarteraRecords::invoice($snapshot, $comercial, '50.00', 100);

    $cartera = CarteraRecords::repository();

    expect($cartera->totals(new ClientQuery(statuses: [ClientStatus::Overdue]))->total)->toBe(1000.0)
        ->and($cartera->totals(new ClientQuery(advisorCode: 12))->total)->toBe(1050.0)
        ->and($cartera->totals(new ClientQuery(advisorCode: 12))->clientCount)->toBe(2)
        ->and($cartera->totals(new ClientQuery(search: 'BODEGA'))->total)->toBe(300.0)
        ->and($cartera->totals(new ClientQuery(onlyOverdue: true))->overdue)->toBe(1050.0)
        ->and($cartera->totals(new ClientQuery(statuses: [ClientStatus::Legal], advisorCode: 12))->aging)->toEqual(new AgingBuckets(over90: 50.0))
        ->and($cartera->totals(new ClientQuery(sort: 'balance', direction: 'desc'))->total)->toBe(1350.0);
});

it('keeps the cutoff date when no client matches the query', function () {
    CarteraRecords::invoice(CarteraRecords::snapshot(), CarteraRecords::client('Agro Arango'), '100.00');

    $totals = CarteraRecords::repository()->totals(new ClientQuery(search: 'zapata'));

    expect($totals->cutoffDate?->toDateString())->toBe('2026-10-15')
        ->and($totals->total)->toBe(0.0)
        ->and($totals->clientCount)->toBe(0)
        ->and($totals->aging)->toEqual(new AgingBuckets);
});

it('leaves soft-deleted clients out of the totals', function () {
    $snapshot = CarteraRecords::snapshot();
    $deleted = CarteraRecords::client('Agro Arango');
    CarteraRecords::invoice($snapshot, $deleted, '100.00');
    CarteraRecords::invoice($snapshot, CarteraRecords::client('Bodega Bedoya'), '40.00');
    $deleted->delete();

    $totals = CarteraRecords::repository()->totals();

    expect($totals->total)->toBe(40.0)
        ->and($totals->clientCount)->toBe(1);
});

it('adds up only the clients an asesor sees', function () {
    $asesor = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $asesor);
    $snapshot = CarteraRecords::snapshot();
    CarteraRecords::invoice($snapshot, CarteraRecords::client('Agro Arango', ['advisor_code' => 12]), '100.00', 5);
    CarteraRecords::invoice($snapshot, CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 20]), '900.00', 5);
    $this->actingAs($asesor);

    $totals = CarteraRecords::repository()->totals();

    expect($totals->total)->toBe(100.0)
        ->and($totals->clientCount)->toBe(1)
        ->and(CarteraRecords::repository()->asSystem()->totals()->total)->toBe(1000.0);
});

it('returns empty totals without a snapshot in scope', function () {
    CarteraRecords::client('Agro Arango');

    expect(CarteraRecords::repository()->totals())->toEqual(PortfolioTotals::empty());

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC), CarteraRecords::client('Casa SDC', ['company' => CompanyCode::SDC]), '100.00');

    expect(CarteraRecords::repository()->totals())->toEqual(PortfolioTotals::empty())
        ->and(CarteraRecords::repository()->totals(new ClientQuery(companies: [CompanyCode::SDC]))->total)->toBe(100.0);
});

it('returns empty totals to guests and to users who see no client', function (?Role $role) {
    CarteraRecords::invoice(CarteraRecords::snapshot(), CarteraRecords::client('Agro Arango'), '100.00');

    if ($role === null) {
        auth()->logout();
    } else {
        $this->actingAs(CoreUsers::create($role, CompanyCode::SGX));
    }

    expect(CarteraRecords::repository()->totals())->toEqual(PortfolioTotals::empty());
})->with([
    'guest' => [null],
    'treasury' => [Role::Treasury],
]);

it('gives the overdue share of the total', function () {
    $snapshot = CarteraRecords::snapshot();
    $client = CarteraRecords::client('Agro Arango');
    CarteraRecords::invoice($snapshot, $client, '1500.00', -2);
    CarteraRecords::invoice($snapshot, $client, '500.00', 31);

    expect(CarteraRecords::repository()->totals()->overduePercent())->toBe(25.0);
});
