<?php

use App\Modules\Cobro\Application\Reports\CommitmentWindow;
use Carbon\CarbonImmutable;

function commitmentWindowAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'America/Bogota');
}

it('takes the commitments from the send to seven days later, the end left out', function () {
    $window = new CommitmentWindow([
        10 => [
            commitmentWindowAt('2026-10-14 16:59:59'),
            commitmentWindowAt('2026-10-14 17:00:00'),
            commitmentWindowAt('2026-10-20 09:00:00'),
            commitmentWindowAt('2026-10-21 17:00:00'),
        ],
    ]);
    $sentAt = commitmentWindowAt('2026-10-14 17:00:00');

    expect($window->first(10, $sentAt)?->toDateTimeString())->toBe('2026-10-14 17:00:00')
        ->and($window->count(10, $sentAt))->toBe(2);
});

it('has no commitments for another client, and none at all for a mail that was not sent', function () {
    $window = new CommitmentWindow([10 => [commitmentWindowAt('2026-10-15 08:00:00')]]);

    expect($window->first(20, commitmentWindowAt('2026-10-14 17:00:00')))->toBeNull()
        ->and($window->count(20, commitmentWindowAt('2026-10-14 17:00:00')))->toBe(0)
        ->and($window->first(10, null))->toBeNull()
        ->and($window->count(10, null))->toBeNull();
});
