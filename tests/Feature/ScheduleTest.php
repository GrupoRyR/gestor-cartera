<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled event whose command contains $command.
 */
function scheduledEvent(string $command): Event
{
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, $command),
    ));

    expect($events)->toHaveCount(1);

    return $events[0];
}

it('drains the queue every minute in the background without overlapping', function () {
    $worker = scheduledEvent('queue:work');

    expect($worker->expression)->toBe('* * * * *')
        ->and($worker->runInBackground)->toBeTrue()
        ->and($worker->withoutOverlapping)->toBeTrue();
});

it('lets a killed queue worker release its lock within minutes, not a day', function () {
    // The worker stops after 50 seconds (--max-time=50), so a 5-minute lock
    // only outlives a worker that died without cleaning up.
    expect(scheduledEvent('queue:work')->expiresAt)->toBe(5);
});

it('prunes failed jobs older than 30 days once a day', function () {
    $prune = scheduledEvent('queue:prune-failed');

    expect((string) $prune->command)->toContain('--hours=720')
        ->and($prune->expression)->toBe('0 0 * * *');
});
