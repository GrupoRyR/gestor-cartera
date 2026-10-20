<?php

namespace App\Modules\Gerencia\Application\Mail;

use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Gerencia\Domain\Mail\MailResultsTally;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;

/**
 * Reads the Cobro emails of a period from the activity log of the clients
 * the user can see.
 */
final class ReadMailResults
{
    public function __construct(
        private readonly GestionLogger $log,
        private readonly MailboxReader $mailbox,
    ) {}

    public function handle(ReportPeriod $period): MailResultsReport
    {
        $limit = max(1, (int) config('gerencia.activity_read_limit', 5000));

        return new MailResultsReport(
            results: MailResultsTally::fromActivities($this->log->recent($limit), $period, $limit),
            unread: $this->mailbox->unreadCount(),
        );
    }
}
