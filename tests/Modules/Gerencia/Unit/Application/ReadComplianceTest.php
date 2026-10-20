<?php

use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Commitments\ReadCompliance;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->fakes->currentCompany->switchTo(null);
    $this->today = CarbonImmutable::parse('2026-10-20');
    $this->period = ReportPeriod::for(PeriodKey::Month, $this->today);

    /**
     * A broken commitment due on the given day of October 2026.
     */
    $this->broken = fn (int $day, ?int $advisor = 7, CompanyCode $company = CompanyCode::SGX) => Make::commitment(
        company: $company,
        advisorCode: $advisor,
        status: CommitmentStatus::Broken,
        dueDate: CarbonImmutable::parse("2026-10-{$day}"),
    );
});

it('measures the compliance of the commitments due in the period', function () {
    $this->fakes->compromisos->add(
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-04')),
        ($this->broken)(6),
        // Due today: inside the period but not yet evaluated.
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-20')),
        // Outside the period: due after today, and due last month.
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-25')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-09-28')),
    );

    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->compliance->fulfilled)->toBe(1)
        ->and($report->rows[0]->compliance->broken)->toBe(1)
        ->and($report->rows[0]->compliance->upcoming)->toBe(1)
        ->and($report->overallRate())->toBe(50.0)
        ->and($report->truncated)->toBeFalse();
});

it('names the advisors by company and code', function () {
    $this->fakes->cartera->addAdvisor(
        Make::advisor(company: CompanyCode::SGX, code: 7, name: 'Carlos Pérez'),
        Make::advisor(company: CompanyCode::SDC, code: 7, name: 'Diana Gómez'),
    );
    $this->fakes->compromisos->add(
        ($this->broken)(6, 7, CompanyCode::SGX),
        ($this->broken)(7, 7, CompanyCode::SDC),
        ($this->broken)(8, 99, CompanyCode::SGX),
        ($this->broken)(9, null, CompanyCode::SGX),
    );

    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    $names = [];
    foreach ($report->rows as $row) {
        $names[$row->compliance->company->value.'/'.($row->compliance->advisorCode ?? 'none')] = $row->advisorName;
    }

    expect($names)->toEqual([
        'SGX/7' => 'Carlos Pérez',
        'SDC/7' => 'Diana Gómez',
        'SGX/99' => null,
        'SGX/none' => null,
    ]);
});

it('reads every page of commitments', function () {
    config(['gerencia.compliance_page_size' => 2]);
    $this->fakes->compromisos->add(
        ($this->broken)(1),
        ($this->broken)(2),
        ($this->broken)(3),
        ($this->broken)(4),
        ($this->broken)(5),
    );

    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    expect($report->rows[0]->compliance->broken)->toBe(5)
        ->and($report->truncated)->toBeFalse();
});

it('stops at the page cap and says the figures are partial', function () {
    config(['gerencia.compliance_page_size' => 2, 'gerencia.compliance_max_pages' => 2]);
    $this->fakes->compromisos->add(
        ($this->broken)(1),
        ($this->broken)(2),
        ($this->broken)(3),
        ($this->broken)(4),
        ($this->broken)(5),
    );

    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    // Oldest due dates first: two pages of two commitments.
    expect($report->rows[0]->compliance->broken)->toBe(4)
        ->and($report->truncated)->toBeTrue();
});

it('is not partial when the last page is exactly the cap', function () {
    config(['gerencia.compliance_page_size' => 2, 'gerencia.compliance_max_pages' => 2]);
    $this->fakes->compromisos->add(
        ($this->broken)(1),
        ($this->broken)(2),
        ($this->broken)(3),
        ($this->broken)(4),
    );

    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    expect($report->rows[0]->compliance->broken)->toBe(4)
        ->and($report->truncated)->toBeFalse();
});

it('has no rows and no rate without commitments', function () {
    $report = $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    expect($report->rows)->toBe([])
        ->and($report->overallRate())->toBeNull()
        ->and($report->truncated)->toBeFalse();
});

it('puts the page of the request back after reading', function () {
    config(['gerencia.compliance_page_size' => 1]);
    $this->fakes->compromisos->add(($this->broken)(1), ($this->broken)(2), ($this->broken)(3));
    $this->app['request']->merge(['page' => '4']);

    $this->app->make(ReadCompliance::class)->handle($this->period, $this->today);

    expect(Paginator::resolveCurrentPage())->toBe(4);
});
