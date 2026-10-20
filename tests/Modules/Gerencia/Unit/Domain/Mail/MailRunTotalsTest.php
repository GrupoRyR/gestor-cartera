<?php

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Gerencia\Domain\Mail\MailRunTotals;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->results = fn (CompanyCode $company, int $runs, int $sent, int $bounced, int $withoutEmail, int $failed, int $replies): MailRunResults => new MailRunResults(
        company: $company,
        kind: MailRunKind::WeeklyCollection,
        from: CarbonImmutable::parse('2026-10-01'),
        to: CarbonImmutable::parse('2026-10-20'),
        runs: $runs,
        sent: $sent,
        bounced: $bounced,
        withoutEmail: $withoutEmail,
        failed: $failed,
        replies: $replies,
    );
});

it('copies the counts of one company', function () {
    $totals = MailRunTotals::of(($this->results)(CompanyCode::SDC, 2, 40, 4, 3, 1, 6));

    expect($totals)->toEqual(new MailRunTotals(
        company: CompanyCode::SDC,
        runs: 2,
        sent: 40,
        bounced: 4,
        withoutEmail: 3,
        failed: 1,
        replies: 6,
    ));
});

it('adds the companies up into a group total', function () {
    $total = MailRunTotals::sum([
        MailRunTotals::of(($this->results)(CompanyCode::SGX, 2, 40, 4, 3, 1, 6)),
        MailRunTotals::of(($this->results)(CompanyCode::SDC, 1, 10, 1, 2, 0, 4)),
    ]);

    expect($total)->toEqual(new MailRunTotals(
        company: null,
        runs: 3,
        sent: 50,
        bounced: 5,
        withoutEmail: 5,
        failed: 1,
        replies: 10,
    ));
});

it('is all zeros without companies', function () {
    $total = MailRunTotals::sum([]);

    expect($total->runs)->toBe(0)
        ->and($total->sent)->toBe(0)
        ->and($total->hasRuns())->toBeFalse();
});

it('derives the delivered mails and the bounce and reply rates', function () {
    $totals = new MailRunTotals(company: CompanyCode::SGX, runs: 1, sent: 200, bounced: 10, withoutEmail: 0, failed: 0, replies: 30);

    expect($totals->delivered())->toBe(190)
        ->and($totals->bounceRate())->toBe(5.0)
        ->and($totals->replyRate())->toBe(15.0)
        ->and($totals->hasRuns())->toBeTrue();
});

it('has no rates when nothing was sent, even with runs', function () {
    // Every client of the run lacked an e-mail address.
    $totals = new MailRunTotals(company: CompanyCode::SGX, runs: 1, sent: 0, bounced: 0, withoutEmail: 8, failed: 0, replies: 0);

    expect($totals->delivered())->toBe(0)
        ->and($totals->bounceRate())->toBeNull()
        ->and($totals->replyRate())->toBeNull()
        ->and($totals->hasRuns())->toBeTrue();
});
