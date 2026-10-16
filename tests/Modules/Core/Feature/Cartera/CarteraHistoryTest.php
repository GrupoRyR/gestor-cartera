<?php

use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * SGX has snapshots from the 12th to the 15th (two loads on the 14th); SDC on
 * the 13th and 14th. Agro and Bodega are SGX clients, Casa SDC is SDC's.
 */
beforeEach(function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));

    $agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12]);
    $bodega = CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 20]);
    $sdc = CarteraRecords::client('Casa SDC', ['company' => CompanyCode::SDC]);

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-12'), $agro, '100.00');

    $sgx13 = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-13');
    CarteraRecords::invoice($sgx13, $agro, '200.00', 1);
    CarteraRecords::invoice($sgx13, $bodega, '20.00');

    // Two loads on the 14th: the second one wins.
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14'), $agro, '9999.00', 2);
    $sgx14 = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14');
    CarteraRecords::invoice($sgx14, $agro, '300.00', 2);
    CarteraRecords::invoice($sgx14, $bodega, '30.00');

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15'), $agro, '400.00', 3);

    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-13'), $sdc, '5000.00', 40);
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SDC, '2026-10-14'), $sdc, '6000.00', 41);
});

/**
 * Each entry as «<cutoff date>: <total>/<overdue>/<clients>».
 *
 * @param  list<PortfolioTotals>  $history
 * @return list<string>
 */
function historyLines(array $history): array
{
    return array_map(
        fn (PortfolioTotals $totals): string => "{$totals->cutoffDate?->toDateString()}: {$totals->total}/{$totals->overdue}/{$totals->clientCount}",
        $history,
    );
}

it('returns one total per cutoff date of the active company in the range, ascending', function () {
    $history = CarteraRecords::repository()->history(CarteraRecords::at('2026-10-13'), CarteraRecords::at('2026-10-15'));

    expect(historyLines($history))->toBe([
        '2026-10-13: 220/200/2',
        '2026-10-14: 330/300/2',
        '2026-10-15: 400/400/1',
    ]);
});

it('includes both ends of the range, whatever their time of day', function () {
    $history = CarteraRecords::repository()->history(CarteraRecords::at('2026-10-14 18:00'), CarteraRecords::at('2026-10-14 06:00'));

    expect(historyLines($history))->toBe(['2026-10-14: 330/300/2']);
});

it('combines the companies that share a cutoff date in the group view', function () {
    CarteraRecords::switchTo(null);

    $history = CarteraRecords::repository()->history(CarteraRecords::at('2026-10-01'), CarteraRecords::at('2026-10-31'));

    expect(historyLines($history))->toBe([
        '2026-10-12: 100/0/1',
        '2026-10-13: 5220/5200/3',
        '2026-10-14: 6330/6300/3',
        '2026-10-15: 400/400/1',
    ])
        ->and($history[1]->overdueClientCount)->toBe(2)
        ->and($history[1]->aging->days31To60)->toBe(5000.0);
});

it('applies the filters and the scope, and ignores the sort', function () {
    $cartera = CarteraRecords::repository();
    $range = [CarteraRecords::at('2026-10-13'), CarteraRecords::at('2026-10-14')];

    expect(historyLines($cartera->history(...$range, query: new ClientQuery(search: 'bodega'))))->toBe([
        '2026-10-13: 20/0/1',
        '2026-10-14: 30/0/1',
    ])
        ->and(historyLines($cartera->history(...$range, query: new ClientQuery(sort: 'balance', direction: 'desc'))))
        ->toBe(historyLines($cartera->history(...$range)));

    $asesor = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    TeamUsers::advisor(CompanyCode::SGX, 20, 'Diana Duque', $asesor);
    $this->actingAs($asesor);

    expect(historyLines(CarteraRecords::repository()->history(...$range)))->toBe([
        '2026-10-13: 20/0/1',
        '2026-10-14: 30/0/1',
    ]);
});

it('returns no history outside the snapshots or without scope', function () {
    expect(CarteraRecords::repository()->history(CarteraRecords::at('2026-09-01'), CarteraRecords::at('2026-09-30')))->toBe([]);

    auth()->logout();

    expect(CarteraRecords::repository()->history(CarteraRecords::at('2026-10-01'), CarteraRecords::at('2026-10-31')))->toBe([])
        ->and(historyLines(CarteraRecords::repository()->asSystem()->history(CarteraRecords::at('2026-10-13'), CarteraRecords::at('2026-10-13'))))
        ->toBe(['2026-10-13: 5220/5200/3']);
});

it('rejects a range that ends before it starts', function () {
    CarteraRecords::repository()->history(CarteraRecords::at('2026-10-15'), CarteraRecords::at('2026-10-14 23:59'));
})->throws(InvalidArgumentException::class);
