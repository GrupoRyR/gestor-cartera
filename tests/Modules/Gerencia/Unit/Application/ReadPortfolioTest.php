<?php

use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Collections\CollectionsReport;
use App\Modules\Gerencia\Application\Portfolio\ReadPortfolio;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Gerencia\Support\PerCompanyHistoryCartera;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-20');
    $this->period = ReportPeriod::for(PeriodKey::Month, $this->today);

    $this->snapshot = fn (string $cutoff, float $total, float $overdue = 0.0): PortfolioTotals => new PortfolioTotals(
        cutoffDate: CarbonImmutable::parse($cutoff),
        total: $total,
        overdue: $overdue,
        clientCount: 1,
        overdueClientCount: 0,
        aging: new AgingBuckets,
    );

    // 300 collected by SGX and 100 by SDC in the month.
    $this->collections = new CollectionsReport([
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 300.0),
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-11'), company: CompanyCode::SDC, total: 100.0),
    ], []);

    /**
     * A reader over a cartera with a client per company and its own history per company.
     */
    $this->reader = function (FakeCurrentCompany $company) {
        $fake = new FakeCarteraRepository($company);
        $fake->cutoffDate = $this->today;
        $fake->addClient(
            Make::clientDetail(Make::client(id: 501, company: CompanyCode::SGX)),
            Make::invoice(clientId: 501, company: CompanyCode::SGX, amount: 700.0, daysOverdue: 10),
        );
        $fake->addClient(
            Make::clientDetail(Make::client(id: 502, company: CompanyCode::SDC)),
            Make::invoice(clientId: 502, company: CompanyCode::SDC, amount: 300.0, daysOverdue: 0),
        );

        $cartera = (new PerCompanyHistoryCartera($fake))
            ->withGroupHistory([
                ($this->snapshot)('2026-09-30', 800.0, 80.0),
                ($this->snapshot)('2026-10-05', 1_000.0, 100.0),
                ($this->snapshot)('2026-10-15', 1_200.0, 240.0),
            ])
            ->withCompanyHistory(CompanyCode::SGX, [
                ($this->snapshot)('2026-09-30', 500.0),
                ($this->snapshot)('2026-10-05', 600.0),
                ($this->snapshot)('2026-10-15', 800.0),
            ])
            ->withCompanyHistory(CompanyCode::SDC, [
                ($this->snapshot)('2026-09-30', 300.0),
                ($this->snapshot)('2026-10-05', 400.0),
                ($this->snapshot)('2026-10-15', 400.0),
            ]);

        return [new ReadPortfolio($cartera, $company), $cartera];
    };
});

it('gives the group first and then each company when the user sees both', function () {
    [$reader] = ($this->reader)(FakeCurrentCompany::group());

    $scopes = $reader->handle($this->period, $this->today, $this->collections);

    expect(array_map(fn ($scope) => $scope->company?->value, $scopes))->toBe([null, 'SGX', 'SDC'])
        ->and(array_map(fn ($scope) => $scope->name, $scopes))->toBe(['Grupo RYR', 'Segurex Latam', 'Soluciones y Derivados']);
});

it('reads the totals of the group and of each company', function () {
    [$reader] = ($this->reader)(FakeCurrentCompany::group());

    [$group, $sgx, $sdc] = $reader->handle($this->period, $this->today, $this->collections);

    expect($group->totals->total)->toBe(1_000.0)
        ->and($group->totals->overdue)->toBe(700.0)
        ->and($sgx->totals->total)->toBe(700.0)
        ->and($sdc->totals->total)->toBe(300.0)
        ->and($sdc->totals->overdue)->toBe(0.0);
});

it('builds the trend of each scope from its own history', function () {
    [$reader] = ($this->reader)(FakeCurrentCompany::group());

    [$group, $sgx, $sdc] = $reader->handle($this->period, $this->today, $this->collections);

    $series = fn ($scope) => array_map(fn ($point) => $point->month->format('Y-m').'/'.$point->total, $scope->trend);

    expect($series($group))->toBe(['2026-09/800', '2026-10/1200'])
        ->and($series($sgx))->toBe(['2026-09/500', '2026-10/800'])
        ->and($series($sdc))->toBe(['2026-09/300', '2026-10/400'])
        ->and($group->trend[1]->overduePercent())->toBe(20.0);
});

it('measures the rotation against the average portfolio of the snapshots inside the period', function () {
    [$reader] = ($this->reader)(FakeCurrentCompany::group());

    [$group, $sgx, $sdc] = $reader->handle($this->period, $this->today, $this->collections);

    // The 30 September snapshot is outside the period, so it does not count.
    expect($group->rotation->averagePortfolio)->toBe(1_100.0)
        ->and($group->rotation->collected)->toBe(400.0)
        ->and($group->rotation->periodDays)->toBe(20)
        ->and($sgx->rotation->averagePortfolio)->toBe(700.0)
        ->and($sgx->rotation->collected)->toBe(300.0)
        ->and($sdc->rotation->averagePortfolio)->toBe(400.0)
        ->and($sdc->rotation->collected)->toBe(100.0)
        ->and($sgx->rotation->times())->toEqualWithDelta(300 / 700, 0.0001);
});

it('falls back to the current portfolio when no snapshot falls inside the period', function () {
    [$reader] = ($this->reader)(FakeCurrentCompany::group());
    // July 2026 has no snapshot in the history.
    $quiet = new ReportPeriod(PeriodKey::PreviousMonth, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

    [$group, $sgx] = $reader->handle($quiet, $this->today, new CollectionsReport([], []));

    expect($group->rotation->averagePortfolio)->toBe($group->totals->total)
        ->and($sgx->rotation->averagePortfolio)->toBe(700.0)
        ->and($group->rotation->times())->toBe(0.0);
});

it('reads the history once per scope, with the company of the scope as query', function () {
    [$reader, $cartera] = ($this->reader)(FakeCurrentCompany::group());

    $reader->handle($this->period, $this->today, $this->collections);

    $queries = array_map(fn (?ClientQuery $query) => $query === null ? null : array_map(fn ($code) => $code->value, $query->companies), $cartera->historyQueries);

    expect($queries)->toBe([null, ['SGX'], ['SDC']])
        // 12 months back from the current month: November 2025 to today.
        ->and($cartera->historyRanges)->toBe([['2025-11-01', '2026-10-20'], ['2025-11-01', '2026-10-20'], ['2025-11-01', '2026-10-20']]);
});

it('starts the history at the start of the period when it goes further back than the trend', function () {
    config(['gerencia.trend_months' => 2]);
    [$reader, $cartera] = ($this->reader)(new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
    $year = ReportPeriod::for(PeriodKey::Year, $this->today);

    $reader->handle($year, $this->today, $this->collections);

    // Two months of trend would start on 1 September, the year on 1 January.
    expect($cartera->historyRanges)->toBe([['2026-01-01', '2026-10-20']]);
});

it('starts the history at the trend window when the period is shorter', function () {
    config(['gerencia.trend_months' => 3]);
    [$reader, $cartera] = ($this->reader)(new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $reader->handle($this->period, $this->today, $this->collections);

    expect($cartera->historyRanges)->toBe([['2026-08-01', '2026-10-20']]);
});

it('gives only the company when the user sees one', function () {
    [$reader, $cartera] = ($this->reader)(new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $scopes = $reader->handle($this->period, $this->today, $this->collections);

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0]->company)->toBe(CompanyCode::SGX)
        ->and($scopes[0]->totals->total)->toBe(700.0)
        ->and($scopes[0]->rotation->collected)->toBe(300.0)
        ->and($cartera->historyRanges)->toHaveCount(1);
});

it('gives only the active company in a single-company view of a user with both', function () {
    [$reader] = ($this->reader)(new FakeCurrentCompany(CompanyCode::SDC));

    $scopes = $reader->handle($this->period, $this->today, $this->collections);

    expect(array_map(fn ($scope) => $scope->company?->value, $scopes))->toBe(['SDC']);
});

it('names the group after the configuration', function () {
    config(['gerencia.group_name' => 'Grupo de prueba']);
    [$reader] = ($this->reader)(FakeCurrentCompany::group());

    $scopes = $reader->handle($this->period, $this->today, $this->collections);

    expect($scopes[0]->name)->toBe('Grupo de prueba');
});

it('gives nothing to a user without companies', function () {
    [$reader, $cartera] = ($this->reader)(FakeCurrentCompany::guest());

    expect($reader->handle($this->period, $this->today, $this->collections))->toBe([])
        ->and($cartera->historyRanges)->toBe([]);
});
