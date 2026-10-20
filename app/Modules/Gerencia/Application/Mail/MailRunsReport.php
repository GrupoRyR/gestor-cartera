<?php

namespace App\Modules\Gerencia\Application\Mail;

/**
 * The outcome of the collection-mail runs of a period, kind by kind, plus
 * the replies and bounces still waiting to be read.
 */
final readonly class MailRunsReport
{
    /**
     * @param  list<MailKindResults>  $kinds  One per MailRunKind, in case order.
     * @param  int  $unread  Unread messages in the mailboxes of the user's scope, whatever the period.
     */
    public function __construct(
        public array $kinds,
        public int $unread,
    ) {}
}
