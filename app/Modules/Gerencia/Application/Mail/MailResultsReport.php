<?php

namespace App\Modules\Gerencia\Application\Mail;

use App\Modules\Gerencia\Domain\Mail\MailResults;

/**
 * The outcome of the Cobro emails in a period, plus the replies and bounces
 * still waiting to be read.
 */
final readonly class MailResultsReport
{
    /**
     * @param  int  $unread  Unread messages in the mailboxes of the user's scope, whatever the period.
     */
    public function __construct(
        public MailResults $results,
        public int $unread,
    ) {}
}
