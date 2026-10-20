<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the collection-mail runs of one company and kind achieved in a
 * period, counted by client: each run mails each client at most once.
 */
final readonly class MailRunResults
{
    /**
     * @param  CarbonImmutable  $from  First day of the period (date only, America/Bogota).
     * @param  CarbonImmutable  $to  Last day of the period, included.
     * @param  int  $runs  Runs scheduled in the period.
     * @param  int  $sent  Clients whose mail was handed to the mailbox, bounced or not.
     * @param  int  $bounced  Those of them whose mail bounced at one of their own
     *                        recipients; a bounced carbon copy does not count.
     * @param  int  $withoutEmail  Clients left out because they have no e-mail address.
     * @param  int  $replies  Clients mailed who answered at least once (a bounce is not
     *                        an answer), whenever the answer arrived.
     *
     * @throws InvalidArgumentException When a count is negative, or there are more
     *                                  bounces or replies than mails sent.
     */
    public function __construct(
        public CompanyCode $company,
        public MailRunKind $kind,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $runs,
        public int $sent,
        public int $bounced,
        public int $withoutEmail,
        public int $replies,
    ) {
        if (min($runs, $sent, $bounced, $withoutEmail, $replies) < 0) {
            throw new InvalidArgumentException('Mail run counts cannot be negative.');
        }

        if ($bounced > $sent || $replies > $sent) {
            throw new InvalidArgumentException('Mail run results cannot have more bounces or replies than mails sent.');
        }
    }

    /**
     * A period without runs: every count is zero.
     */
    public static function empty(CompanyCode $company, MailRunKind $kind, CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            company: $company,
            kind: $kind,
            from: $from,
            to: $to,
            runs: 0,
            sent: 0,
            bounced: 0,
            withoutEmail: 0,
            replies: 0,
        );
    }

    /**
     * Clients whose mail was sent and did not bounce.
     */
    public function delivered(): int
    {
        return $this->sent - $this->bounced;
    }
}
