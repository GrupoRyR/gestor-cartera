<?php

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Domain\Commitments\AdvisorCompliance;
use App\Modules\Gerencia\Domain\Commitments\ComplianceTally;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-20');
});

it('counts a payment made on or before the due date as fulfilled on time', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-10'), fulfilledAt: CarbonImmutable::parse('2026-10-10 18:00:00')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-10'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
    ], $this->today);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->fulfilled)->toBe(2)
        ->and($rows[0]->fulfilledOnTime)->toBe(2);
});

it('counts a late payment as fulfilled but not on time', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-10'), fulfilledAt: CarbonImmutable::parse('2026-10-11 08:00:00')),
    ], $this->today);

    expect($rows[0]->fulfilled)->toBe(1)
        ->and($rows[0]->fulfilledOnTime)->toBe(0);
});

it('classifies the other statuses by their due date', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-01')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-19')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-20')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-25')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Cancelled, dueDate: CarbonImmutable::parse('2026-10-02')),
    ], $this->today);

    expect($rows[0]->broken)->toBe(1)
        ->and($rows[0]->overduePending)->toBe(1)
        ->and($rows[0]->upcoming)->toBe(2)
        ->and($rows[0]->cancelled)->toBe(1)
        ->and($rows[0]->fulfilled)->toBe(0);
});

it('evaluates fulfilled, broken and overdue pending commitments only', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Fulfilled, amount: 100.0, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, amount: 200.0, dueDate: CarbonImmutable::parse('2026-10-06')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, amount: 300.0, dueDate: CarbonImmutable::parse('2026-10-07')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, amount: 400.0, dueDate: CarbonImmutable::parse('2026-10-30')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Cancelled, amount: 500.0, dueDate: CarbonImmutable::parse('2026-10-08')),
    ], $this->today);

    expect($rows[0]->evaluated())->toBe(3)
        ->and($rows[0]->rate())->toEqualWithDelta(100 / 3, 0.0001)
        ->and($rows[0]->amountEvaluated)->toBe(600.0)
        ->and($rows[0]->amountFulfilled)->toBe(100.0);
});

it('has no rate while nothing has been evaluated', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-28')),
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Cancelled, dueDate: CarbonImmutable::parse('2026-10-02')),
    ], $this->today);

    expect($rows[0]->evaluated())->toBe(0)
        ->and($rows[0]->rate())->toBeNull();
});

it('groups by company and advisor, keeping advisors without a code apart', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(company: CompanyCode::SGX, advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-01')),
        Make::commitment(company: CompanyCode::SDC, advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-01')),
        Make::commitment(company: CompanyCode::SGX, advisorCode: null, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-01')),
        Make::commitment(company: CompanyCode::SGX, advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-02')),
    ], $this->today);

    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row->company->value.'/'.($row->advisorCode ?? 'none')] = $row->broken;
    }

    expect($rows)->toHaveCount(3)
        ->and($byKey)->toEqual(['SGX/7' => 2, 'SDC/7' => 1, 'SGX/none' => 1]);
});

it('orders by lowest rate first, with unrated advisors last', function () {
    $fulfilled = fn (int $advisor): array => [
        Make::commitment(advisorCode: $advisor, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
    ];
    $broken = fn (int $advisor): array => [
        Make::commitment(advisorCode: $advisor, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-05')),
    ];

    $rows = ComplianceTally::fromCommitments([
        ...$fulfilled(1),
        ...$fulfilled(2),
        ...$broken(2),
        ...$broken(3),
        Make::commitment(advisorCode: 4, status: CommitmentStatus::Pending, dueDate: CarbonImmutable::parse('2026-10-28')),
    ], $this->today);

    expect(array_map(fn (AdvisorCompliance $row): ?int => $row->advisorCode, $rows))->toBe([3, 2, 1, 4]);
});

it('breaks rate ties with the larger evaluated amount, then company and advisor code', function () {
    $broken = fn (CompanyCode $company, ?int $advisor, float $amount): Commitment => Make::commitment(
        company: $company,
        advisorCode: $advisor,
        amount: $amount,
        status: CommitmentStatus::Broken,
        dueDate: CarbonImmutable::parse('2026-10-05'),
    );

    $rows = ComplianceTally::fromCommitments([
        $broken(CompanyCode::SDC, 1, 100.0),
        $broken(CompanyCode::SGX, 9, 100.0),
        $broken(CompanyCode::SGX, 2, 100.0),
        $broken(CompanyCode::SGX, null, 100.0),
        $broken(CompanyCode::SGX, 5, 900.0),
    ], $this->today);

    expect(array_map(fn (AdvisorCompliance $row): string => $row->company->value.'/'.($row->advisorCode ?? 'none'), $rows))
        ->toBe(['SGX/5', 'SGX/2', 'SGX/9', 'SGX/none', 'SDC/1']);
});

it('computes the overall rate over every advisor', function () {
    $rows = ComplianceTally::fromCommitments([
        Make::commitment(advisorCode: 1, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
        Make::commitment(advisorCode: 1, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
        Make::commitment(advisorCode: 1, status: CommitmentStatus::Fulfilled, dueDate: CarbonImmutable::parse('2026-10-05'), fulfilledAt: CarbonImmutable::parse('2026-10-05')),
        Make::commitment(advisorCode: 2, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-05')),
    ], $this->today);

    expect(ComplianceTally::overallRate($rows))->toBe(75.0);
});

it('has no overall rate without evaluated commitments', function () {
    expect(ComplianceTally::overallRate([]))->toBeNull()
        ->and(ComplianceTally::fromCommitments([], $this->today))->toBe([]);
});
