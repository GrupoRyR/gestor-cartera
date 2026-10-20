<?php

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Collections\ReadCollections;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-20'));

    $this->day = fn (string $date, CompanyCode $company, float $total) => Make::dailyCollection(
        date: CarbonImmutable::parse($date),
        company: $company,
        total: $total,
    );

    $this->fakes->recaudo->addDaily(
        ($this->day)('2026-10-12', CompanyCode::SDC, 40.0),
        ($this->day)('2026-10-05', CompanyCode::SGX, 100.0),
        ($this->day)('2026-10-12', CompanyCode::SGX, 200.0),
        ($this->day)('2026-10-14', CompanyCode::SDC, 60.0),
        ($this->day)('2026-09-30', CompanyCode::SGX, 999.0),
    );
});

it('reads the collections of every company in the group scope, inside the period', function () {
    $this->fakes->currentCompany->switchTo(null);

    $report = $this->app->make(ReadCollections::class)->handle($this->period);

    expect(array_map(fn ($day) => $day->date->toDateString().'/'.$day->company->value, $report->daily))
        ->toBe(['2026-10-05/SGX', '2026-10-12/SGX', '2026-10-12/SDC', '2026-10-14/SDC'])
        ->and($report->total())->toBe(400.0)
        ->and($report->total(CompanyCode::SGX))->toBe(300.0)
        ->and($report->total(CompanyCode::SDC))->toBe(100.0);
});

it('never reads a company outside the scope of the user', function () {
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    $report = $this->app->make(ReadCollections::class)->handle($this->period);

    expect(array_values(array_unique(array_map(fn ($day) => $day->company->value, $report->daily))))->toBe(['SGX'])
        ->and(array_values(array_unique(array_map(fn ($week) => $week->company->value, $report->weekly))))->toBe(['SGX'])
        ->and($report->total())->toBe(300.0)
        ->and($report->total(CompanyCode::SDC))->toBe(0.0);
});

it('reads only the active company when the user works in one of them', function () {
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

    $report = $this->app->make(ReadCollections::class)->handle($this->period);

    expect($report->total())->toBe(100.0)
        ->and($report->total(CompanyCode::SGX))->toBe(0.0);
});

it('orders the weeks by start date and then by company', function () {
    $this->fakes->currentCompany->switchTo(null);

    $report = $this->app->make(ReadCollections::class)->handle($this->period);

    // 5 Oct is in the week of Monday 5 Oct; 12 and 14 Oct in the week of Monday 12 Oct.
    expect(array_map(fn ($week) => $week->weekStart->toDateString().'/'.$week->company->value.'/'.$week->total, $report->weekly))
        ->toBe(['2026-10-05/SGX/100', '2026-10-12/SGX/200', '2026-10-12/SDC/100']);
});

it('adds the companies together for each day', function () {
    $this->fakes->currentCompany->switchTo(null);

    $report = $this->app->make(ReadCollections::class)->handle($this->period);

    expect(array_map(fn ($day) => $day->date->toDateString().'/'.$day->total, $report->dayTotals()))
        ->toBe(['2026-10-05/100', '2026-10-12/240', '2026-10-14/60']);
});

it('is empty when nothing was collected in the period', function () {
    $this->fakes->currentCompany->switchTo(null);
    $previous = ReportPeriod::for(PeriodKey::PreviousMonth, CarbonImmutable::parse('2026-12-10'));

    $report = $this->app->make(ReadCollections::class)->handle($previous);

    expect($report->isEmpty())->toBeTrue()
        ->and($report->total())->toBe(0.0)
        ->and($report->dayTotals())->toBe([]);
});

it('reads nothing for a user without companies', function () {
    $this->app->instance(CurrentCompany::class, FakeCurrentCompany::guest());

    expect($this->app->make(ReadCollections::class)->handle($this->period)->isEmpty())->toBeTrue();
});
