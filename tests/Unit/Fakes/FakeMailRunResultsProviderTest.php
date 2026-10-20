<?php

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeMailRunResultsProvider;

function fakeMailRunDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota');
}

beforeEach(function () {
    $this->provider = (new FakeMailRunResultsProvider)
        ->add(CompanyCode::SGX, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-07 17:00'), sent: 10, bounced: 1, withoutEmail: 2, failed: 1, replies: 3)
        ->add(CompanyCode::SGX, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-31 23:30'), sent: 5, failed: 2, replies: 1)
        ->add(CompanyCode::SGX, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-11-01 09:00'), sent: 7)
        ->add(CompanyCode::SGX, MailRunKind::Circularization, fakeMailRunDay('2026-10-01 09:00'), sent: 40, withoutEmail: 4)
        ->add(CompanyCode::SDC, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-07 17:00'), sent: 3, bounced: 3);
});

it('sums the runs of the company and kind scheduled in the period', function () {
    $results = $this->provider->results(CompanyCode::SGX, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-01'), fakeMailRunDay('2026-10-31'));

    expect($results)->toEqual(new MailRunResults(
        company: CompanyCode::SGX,
        kind: MailRunKind::WeeklyCollection,
        from: fakeMailRunDay('2026-10-01'),
        to: fakeMailRunDay('2026-10-31'),
        runs: 2,
        sent: 15,
        bounced: 1,
        withoutEmail: 2,
        failed: 3,
        replies: 4,
    ))->and($results->delivered())->toBe(14);
});

it('compares the scheduled moment as a Bogotá calendar day', function () {
    // 23:30 in Bogotá on October 31 is November 1 in UTC: it stays in October.
    $provider = (new FakeMailRunResultsProvider)
        ->add(CompanyCode::SDC, MailRunKind::WeeklyCollection, CarbonImmutable::parse('2026-11-01 04:30', 'UTC'), sent: 1);

    expect($provider->results(CompanyCode::SDC, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-31'), fakeMailRunDay('2026-10-31'))->runs)->toBe(1)
        ->and($provider->results(CompanyCode::SDC, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-11-01'), fakeMailRunDay('2026-11-01'))->runs)->toBe(0);
});

it('keeps each company and kind apart and answers zeros without runs', function () {
    $sdc = $this->provider->results(CompanyCode::SDC, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-01'), fakeMailRunDay('2026-10-31'));
    $circularization = $this->provider->results(CompanyCode::SGX, MailRunKind::Circularization, fakeMailRunDay('2026-10-01'), fakeMailRunDay('2026-10-31'));
    $none = $this->provider->results(CompanyCode::SDC, MailRunKind::Circularization, fakeMailRunDay('2026-10-01'), fakeMailRunDay('2026-10-31'));

    expect([$sdc->runs, $sdc->sent, $sdc->bounced, $sdc->delivered()])->toBe([1, 3, 3, 0])
        ->and([$circularization->runs, $circularization->sent, $circularization->withoutEmail])->toBe([1, 40, 4])
        ->and($none)->toEqual(MailRunResults::empty(CompanyCode::SDC, MailRunKind::Circularization, fakeMailRunDay('2026-10-01'), fakeMailRunDay('2026-10-31')));
});

it('rejects a period that ends before it starts', function () {
    expect(fn () => $this->provider->results(CompanyCode::SGX, MailRunKind::WeeklyCollection, fakeMailRunDay('2026-10-02'), fakeMailRunDay('2026-10-01')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a run with impossible counts', function (int $sent, int $bounced, int $withoutEmail, int $failed, int $replies) {
    expect(fn () => (new FakeMailRunResultsProvider)->add(
        CompanyCode::SGX,
        MailRunKind::WeeklyCollection,
        fakeMailRunDay('2026-10-07 17:00'),
        sent: $sent,
        bounced: $bounced,
        withoutEmail: $withoutEmail,
        failed: $failed,
        replies: $replies,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'negative count' => [-1, 0, 0, 0, 0],
    'more bounces than sends' => [1, 2, 0, 0, 0],
    'more replies than sends' => [1, 0, 0, 0, 2],
    'negative clients without e-mail' => [0, 0, -1, 0, 0],
    'negative failed sends' => [0, 0, 0, -1, 0],
]);

it('is bound by ContractFakes::install()', function () {
    $fakes = ContractFakes::install($this->app);

    expect(app(MailRunResultsProvider::class))->toBe($fakes->mailRuns);
});

it('labels the run kinds with the values Cobro stores', function () {
    expect(MailRunKind::options())->toBe([
        'cobro_semanal' => 'Cobro semanal',
        'circularizacion' => 'Circularización',
    ]);
});
