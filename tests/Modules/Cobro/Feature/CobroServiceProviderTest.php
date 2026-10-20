<?php

use App\Modules\Cobro\Infrastructure\Mail\DatabaseMailboxReader;
use App\Modules\Cobro\Infrastructure\Mail\GraphMailSender;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled event of a command, or null.
 */
function cobroScheduled(string $command): ?ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command));
}

it('implements the mail contracts of Core', function () {
    expect(app(MailSender::class))->toBeInstanceOf(GraphMailSender::class)
        ->and(app(MailboxReader::class))->toBeInstanceOf(DatabaseMailboxReader::class);
});

it('reads the inboxes every five minutes', function () {
    $event = cobroScheduled('cobro:sincronizar-buzones');

    expect($event)->toBeInstanceOf(ScheduledEvent::class)
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});
