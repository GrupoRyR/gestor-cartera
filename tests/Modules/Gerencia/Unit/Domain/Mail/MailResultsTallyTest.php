<?php

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Gerencia\Domain\Mail\MailResults;
use App\Modules\Gerencia\Domain\Mail\MailResultsTally;
use App\Modules\Gerencia\Domain\Period\PeriodKey;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

beforeEach(function () {
    // The period is September 2026.
    $this->period = ReportPeriod::for(PeriodKey::PreviousMonth, CarbonImmutable::parse('2026-10-20'));

    $this->sent = fn (string $at, array $metadata = ['cobro_run_item_id' => 10]): Activity => Make::activity(
        type: ActivityType::EmailSent,
        occurredAt: CarbonImmutable::parse($at),
        metadata: $metadata,
    );
    $this->received = fn (string $at, array $metadata): Activity => Make::activity(
        type: ActivityType::EmailReceived,
        occurredAt: CarbonImmutable::parse($at),
        metadata: $metadata,
    );
});

it('counts the emails sent by Cobro', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-09-02 09:00:00'),
        ($this->sent)('2026-09-03 09:00:00', ['cobro_run_item_id' => 11]),
    ], $this->period, 5000);

    expect($results->sent)->toBe(2)
        ->and($results->replies)->toBe(0)
        ->and($results->bounces)->toBe(0);
});

it('ignores emails that did not come from Cobro', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-09-02 09:00:00', []),
        ($this->sent)('2026-09-02 10:00:00', ['other_key' => 1]),
        ($this->sent)('2026-09-02 11:00:00', ['cobro_run_item_id' => null]),
        ($this->received)('2026-09-03 09:00:00', []),
        ($this->received)('2026-09-03 10:00:00', ['cobro_run_item_id' => 10]),
        Make::activity(type: ActivityType::Call, occurredAt: CarbonImmutable::parse('2026-09-04'), metadata: ['cobro_run_item_id' => 10]),
        Make::activity(type: ActivityType::Note, occurredAt: CarbonImmutable::parse('2026-09-04'), metadata: ['cobro_inbound_id' => 'x']),
    ], $this->period, 5000);

    expect($results->sent)->toBe(0)
        ->and($results->replies)->toBe(0)
        ->and($results->bounces)->toBe(0);
});

it('tells replies from bounces by the bounce flag', function (mixed $flag, bool $isBounce) {
    $results = MailResultsTally::fromActivities([
        ($this->received)('2026-09-05 09:00:00', ['cobro_inbound_id' => 'm-1', 'bounce' => $flag]),
    ], $this->period, 5000);

    expect($results->bounces)->toBe($isBounce ? 1 : 0)
        ->and($results->replies)->toBe($isBounce ? 0 : 1);
})->with([
    'true' => [true, true],
    'one' => [1, true],
    'string one' => ['1', true],
    'false' => [false, false],
    'zero' => [0, false],
    'string false' => ['false', false],
    'null' => [null, false],
]);

it('counts a reply without a bounce flag as a reply', function () {
    $results = MailResultsTally::fromActivities([
        ($this->received)('2026-09-05 09:00:00', ['cobro_inbound_id' => 'm-1']),
    ], $this->period, 5000);

    expect($results->replies)->toBe(1)->and($results->bounces)->toBe(0);
});

it('counts only the entries whose date is inside the period', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-08-31 23:59:59'),
        ($this->sent)('2026-09-01 00:00:00'),
        ($this->sent)('2026-09-30 23:59:59'),
        ($this->sent)('2026-10-01 00:00:00'),
        ($this->received)('2026-08-31 12:00:00', ['cobro_inbound_id' => 'a']),
        ($this->received)('2026-09-15 12:00:00', ['cobro_inbound_id' => 'b']),
    ], $this->period, 5000);

    expect($results->sent)->toBe(2)->and($results->replies)->toBe(1);
});

it('is not truncated while the read stayed below its limit', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-09-20 09:00:00'),
        ($this->sent)('2026-09-25 09:00:00'),
    ], $this->period, 3);

    expect($results->truncated)->toBeFalse();
});

it('is truncated when the read hit its limit and its oldest entry is newer than the period start', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-09-20 09:00:00'),
        ($this->sent)('2026-09-25 09:00:00'),
    ], $this->period, 2);

    expect($results->truncated)->toBeTrue();
});

it('is not truncated when the read hit its limit but reached back before the period', function () {
    $results = MailResultsTally::fromActivities([
        ($this->sent)('2026-08-30 09:00:00'),
        ($this->sent)('2026-09-25 09:00:00'),
    ], $this->period, 2);

    expect($results->truncated)->toBeFalse();
});

it('looks at the oldest entry of the whole read, whatever its type', function () {
    $results = MailResultsTally::fromActivities([
        Make::activity(type: ActivityType::Call, occurredAt: CarbonImmutable::parse('2026-08-15')),
        ($this->sent)('2026-09-25 09:00:00'),
    ], $this->period, 2);

    expect($results->truncated)->toBeFalse();
});

it('is not truncated without activity', function () {
    $results = MailResultsTally::fromActivities([], $this->period, 5000);

    expect($results)->toEqual(new MailResults(0, 0, 0, false));
});

it('derives delivered emails and the bounce and reply rates', function () {
    $results = new MailResults(sent: 200, replies: 30, bounces: 10, truncated: false);

    expect($results->delivered())->toBe(190)
        ->and($results->bounceRate())->toBe(5.0)
        ->and($results->replyRate())->toBe(15.0);
});

it('has no rates and no delivered emails when nothing was sent', function () {
    $results = new MailResults(sent: 0, replies: 2, bounces: 5, truncated: false);

    expect($results->delivered())->toBe(0)
        ->and($results->bounceRate())->toBeNull()
        ->and($results->replyRate())->toBeNull();
});
