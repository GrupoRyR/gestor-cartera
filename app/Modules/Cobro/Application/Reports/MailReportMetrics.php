<?php

namespace App\Modules\Cobro\Application\Reports;

/**
 * The figures over every item that matches a MailReportFilter.
 *
 * sent counts the items enviado or rebotado (the mailbox accepted the
 * mail, as in MailRunResults); clients, the distinct clients among them;
 * replies, the sent items answered by at least one message that is not a
 * bounce; medianFirstReply, the median seconds from the send to its first
 * reply. teamReplies counts the replies the team sent from the app
 * (ReplySender) in the date range and companies only: the other filters
 * do not apply to it.
 */
final readonly class MailReportMetrics
{
    public function __construct(
        public int $sent,
        public int $failed,
        public int $bounced,
        public int $clients,
        public int $replies,
        public ?float $medianFirstReply,
        public int $teamReplies,
    ) {}
}
