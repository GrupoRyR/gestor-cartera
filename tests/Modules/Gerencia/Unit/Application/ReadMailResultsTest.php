<?php

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use App\Modules\Gerencia\Application\Mail\MailKindResults;
use App\Modules\Gerencia\Application\Mail\ReadMailResults;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    // 1 to 20 October 2026.
    $this->period = ReportPeriod::for(PeriodKey::Month, CarbonImmutable::parse('2026-10-20'));

    $this->run = fn (CompanyCode $company, MailRunKind $kind, string $on, int $sent = 0, int $bounced = 0, int $withoutEmail = 0, int $failed = 0, int $replies = 0) => $this->fakes->mailRuns->add(
        $company, $kind, CarbonImmutable::parse($on, 'America/Bogota'), $sent, $bounced, $withoutEmail, $failed, $replies,
    );

    /**
     * The results of one kind, by its value.
     */
    $this->kind = fn ($report, MailRunKind $kind): MailKindResults => array_values(array_filter(
        $report->kinds,
        fn (MailKindResults $results): bool => $results->kind === $kind,
    ))[0];

    /**
     * Swaps the provider for a spy that records every company and kind asked for.
     */
    $this->spyOnMailRuns = function (): ArrayObject {
        $calls = new ArrayObject;
        $inner = $this->fakes->mailRuns;

        $this->app->instance(MailRunResultsProvider::class, new class($inner, $calls) implements MailRunResultsProvider
        {
            public function __construct(private MailRunResultsProvider $inner, private ArrayObject $calls) {}

            public function results(CompanyCode $company, MailRunKind $kind, CarbonImmutable $from, CarbonImmutable $to): MailRunResults
            {
                $this->calls->append($company->value.'/'.$kind->value);

                return $this->inner->results($company, $kind, $from, $to);
            }
        });

        return $calls;
    };
});

it('splits the results by kind and by company in the group view', function () {
    $this->fakes->currentCompany->switchTo(null);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 40, bounced: 4, withoutEmail: 3, failed: 1, replies: 6);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-12 08:00', sent: 10, bounced: 1, replies: 2);
    ($this->run)(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 20, bounced: 2, withoutEmail: 1, replies: 5);
    ($this->run)(CompanyCode::SDC, MailRunKind::Circularization, '2026-10-01 07:00', sent: 30, bounced: 3, failed: 2, replies: 9);

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);
    $weekly = ($this->kind)($report, MailRunKind::WeeklyCollection);
    $circularization = ($this->kind)($report, MailRunKind::Circularization);

    expect(array_map(fn (MailKindResults $results) => $results->kind, $report->kinds))->toBe([MailRunKind::WeeklyCollection, MailRunKind::Circularization])
        ->and(array_map(fn ($row) => $row->company, $weekly->companies))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($weekly->companies[0]->runs)->toBe(2)
        ->and($weekly->companies[0]->sent)->toBe(50)
        ->and($weekly->companies[0]->bounced)->toBe(5)
        ->and($weekly->companies[0]->withoutEmail)->toBe(3)
        ->and($weekly->companies[0]->failed)->toBe(1)
        ->and($weekly->companies[0]->replies)->toBe(8)
        ->and($weekly->companies[1]->sent)->toBe(20)
        ->and($circularization->companies[0]->runs)->toBe(0)
        ->and($circularization->companies[1]->sent)->toBe(30)
        ->and($circularization->companies[1]->failed)->toBe(2);
});

it('adds the companies of the scope up into a group total per kind', function () {
    $this->fakes->currentCompany->switchTo(null);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 40, bounced: 4, withoutEmail: 3, failed: 1, replies: 6);
    ($this->run)(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 20, bounced: 2, withoutEmail: 1, replies: 5);
    ($this->run)(CompanyCode::SDC, MailRunKind::Circularization, '2026-10-01 07:00', sent: 30, replies: 9);

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);
    $weekly = ($this->kind)($report, MailRunKind::WeeklyCollection)->total;
    $circularization = ($this->kind)($report, MailRunKind::Circularization)->total;

    expect($weekly->company)->toBeNull()
        ->and($weekly->runs)->toBe(2)
        ->and($weekly->sent)->toBe(60)
        ->and($weekly->bounced)->toBe(6)
        ->and($weekly->delivered())->toBe(54)
        ->and($weekly->withoutEmail)->toBe(4)
        ->and($weekly->failed)->toBe(1)
        ->and($weekly->replies)->toBe(11)
        ->and($weekly->bounceRate())->toBe(10.0)
        ->and($circularization->runs)->toBe(1)
        ->and($circularization->sent)->toBe(30)
        ->and($circularization->replyRate())->toBe(30.0);
});

it('counts only the runs scheduled inside the period, both ends included', function () {
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-09-30 23:59', sent: 1000);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-01 00:00', sent: 10);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-20 23:59', sent: 5);
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-21 00:00', sent: 2000);

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);
    $weekly = ($this->kind)($report, MailRunKind::WeeklyCollection)->total;

    expect($weekly->runs)->toBe(2)->and($weekly->sent)->toBe(15);
});

it('never reads, nor asks the provider for, a company outside the scope', function () {
    $calls = ($this->spyOnMailRuns)();
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 12);
    ($this->run)(CompanyCode::SDC, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 777);
    ($this->run)(CompanyCode::SDC, MailRunKind::Circularization, '2026-10-01 07:00', sent: 888);

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);
    $weekly = ($this->kind)($report, MailRunKind::WeeklyCollection);
    $circularization = ($this->kind)($report, MailRunKind::Circularization);

    expect($calls->getArrayCopy())->toBe(['SGX/cobro_semanal', 'SGX/circularizacion'])
        ->and(array_map(fn ($row) => $row->company, $weekly->companies))->toBe([CompanyCode::SGX])
        ->and($weekly->total->sent)->toBe(12)
        ->and($circularization->total->sent)->toBe(0);
});

it('asks the provider once per company and kind in the group view', function () {
    $this->fakes->currentCompany->switchTo(null);
    $calls = ($this->spyOnMailRuns)();

    $this->app->make(ReadMailResults::class)->handle($this->period);

    expect($calls->getArrayCopy())->toBe(['SGX/cobro_semanal', 'SDC/cobro_semanal', 'SGX/circularizacion', 'SDC/circularizacion']);
});

it('has no rates and no runs for a kind without sends', function () {
    ($this->run)(CompanyCode::SGX, MailRunKind::WeeklyCollection, '2026-10-05 08:00', sent: 4, replies: 1);

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);
    $circularization = ($this->kind)($report, MailRunKind::Circularization);

    expect(($this->kind)($report, MailRunKind::WeeklyCollection)->hasRuns())->toBeTrue()
        ->and($circularization->hasRuns())->toBeFalse()
        ->and($circularization->total->bounceRate())->toBeNull()
        ->and($circularization->total->replyRate())->toBeNull();
});

it('ignores the activity log', function () {
    $this->app->instance(GestionLogger::class, Mockery::mock(GestionLogger::class, function ($mock) {
        $mock->shouldNotReceive('recent');
    }));

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);

    expect(($this->kind)($report, MailRunKind::WeeklyCollection)->total->sent)->toBe(0);
});

it('does not count the Cobro entries of the activity log', function () {
    $this->fakes->gestiones->add(
        Make::activity(type: ActivityType::EmailSent, occurredAt: CarbonImmutable::parse('2026-10-05 09:00'), metadata: ['cobro_run_item_id' => 1]),
        Make::activity(type: ActivityType::EmailReceived, occurredAt: CarbonImmutable::parse('2026-10-05 10:00'), metadata: ['cobro_inbound_id' => 'a']),
    );

    $report = $this->app->make(ReadMailResults::class)->handle($this->period);

    foreach ($report->kinds as $results) {
        expect($results->total->sent)->toBe(0)->and($results->total->replies)->toBe(0);
    }
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
