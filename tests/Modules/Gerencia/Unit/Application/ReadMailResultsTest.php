<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Gerencia\Application\Mail\ReadMailResults;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-20'));

    $this->sent = fn (string $at) => Make::activity(
        type: ActivityType::EmailSent,
        occurredAt: CarbonImmutable::parse($at),
        metadata: ['cobro_run_item_id' => 1],
    );
});

it('counts the Cobro emails of the period from the activity log', function () {
    $this->fakes->gestiones->add(
        ($this->sent)('2026-10-02 09:00:00'),
        ($this->sent)('2026-10-03 09:00:00'),
        ($this->sent)('2026-09-30 09:00:00'),
        Make::activity(type: ActivityType::EmailReceived, occurredAt: CarbonImmutable::parse('2026-10-04 09:00:00'), metadata: ['cobro_inbound_id' => 'a']),
        Make::activity(type: ActivityType::EmailReceived, occurredAt: CarbonImmutable::parse('2026-10-05 09:00:00'), metadata: ['cobro_inbound_id' => 'b', 'bounce' => true]),
        Make::activity(type: ActivityType::Call, occurredAt: CarbonImmutable::parse('2026-10-06 09:00:00')),
    );

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);

    expect($report->results->sent)->toBe(2)
        ->and($report->results->replies)->toBe(1)
        ->and($report->results->bounces)->toBe(1)
        ->and($report->results->truncated)->toBeFalse();
});

it('flags the results as partial when the log was read up to its limit', function () {
    config(['gerencia.activity_read_limit' => 2]);
    $this->fakes->gestiones->add(
        ($this->sent)('2026-10-02 09:00:00'),
        ($this->sent)('2026-10-03 09:00:00'),
        ($this->sent)('2026-10-04 09:00:00'),
    );

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);

    // Only the two newest entries were read.
    expect($report->results->sent)->toBe(2)
        ->and($report->results->truncated)->toBeTrue();
});

it('counts the unread messages of the companies in the scope', function () {
    $this->fakes->mailbox->add(
        Make::inboundMail(company: CompanyCode::SGX, read: false),
        Make::inboundMail(company: CompanyCode::SGX, read: true),
        Make::inboundMail(company: CompanyCode::SDC, read: false),
        Make::inboundMail(company: CompanyCode::SDC, read: false),
    );

    $this->fakes->currentCompany->switchTo(CompanyCode::SGX);
    $single = $this->app->make(ReadMailResults::class)->handle($this->period);

    $this->fakes->currentCompany->switchTo(null);
    $group = $this->app->make(ReadMailResults::class)->handle($this->period);

    expect($single->unread)->toBe(1)
        ->and($group->unread)->toBe(3);
});

it('is empty without activity or messages', function () {
    $report = $this->app->make(ReadMailResults::class)->handle($this->period);

    expect($report->results->sent)->toBe(0)
        ->and($report->results->truncated)->toBeFalse()
        ->and($report->unread)->toBe(0);
});
