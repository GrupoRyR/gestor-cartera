<?php

use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Board\BuildManagementBoard;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00'));
    $this->fakes = ContractFakes::install($this->app);
});

it('wires the readers for the group view', function () {
    $this->fakes->currentCompany->switchTo(null);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-19');
    $this->fakes->recaudo->addDaily(
        Make::dailyCollection(date: CarbonImmutable::parse('2026-10-10'), company: CompanyCode::SGX, total: 250.0),
    );
    $this->fakes->compromisos->add(
        Make::commitment(advisorCode: 7, status: CommitmentStatus::Broken, dueDate: CarbonImmutable::parse('2026-10-03')),
    );

    $board = $this->app->make(BuildManagementBoard::class)->handle(PeriodKey::Month);

    expect($board->today->toDateString())->toBe('2026-10-20')
        ->and($board->period->key)->toBe(PeriodKey::Month)
        ->and($board->period->from->toDateString())->toBe('2026-10-01')
        ->and($board->scopeName)->toBe('Grupo RYR')
        ->and(array_map(fn ($scope) => $scope->company?->value, $board->scopes))->toBe([null, 'SGX', 'SDC'])
        ->and($board->collections->total())->toBe(250.0)
        ->and($board->compliance->rows)->toHaveCount(1)
        ->and($board->mail->unread)->toBe(0)
        ->and($board->hasPortfolio())->toBeTrue();
});

it('names the active company and keeps only its figures', function () {
    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

    $board = $this->app->make(BuildManagementBoard::class)->handle(PeriodKey::Year);

    expect($board->scopeName)->toBe('Soluciones y Derivados')
        ->and($board->period->key)->toBe(PeriodKey::Year)
        ->and($board->period->from->toDateString())->toBe('2026-01-01')
        ->and(array_map(fn ($scope) => $scope->company?->value, $board->scopes))->toBe(['SDC']);
});

it('has no portfolio until a snapshot exists', function () {
    $board = $this->app->make(BuildManagementBoard::class)->handle(PeriodKey::Month);

    expect($board->hasPortfolio())->toBeFalse();
});
