<?php

namespace App\Modules\Gerencia\Domain\Mail;

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * What the collection-mail runs of one kind achieved in a period, for one
 * company or added up for the group, with the rates the dashboard shows.
 * Every count is of clients, as in MailRunResults.
 */
final readonly class MailRunTotals
{
    /**
     * @param  ?CompanyCode  $company  Null for the group total.
     */
    public function __construct(
        public ?CompanyCode $company,
        public int $runs,
        public int $sent,
        public int $bounced,
        public int $withoutEmail,
        public int $failed,
        public int $replies,
    ) {}

    public static function of(MailRunResults $results): self
    {
        return new self(
            company: $results->company,
            runs: $results->runs,
            sent: $results->sent,
            bounced: $results->bounced,
            withoutEmail: $results->withoutEmail,
            failed: $results->failed,
            replies: $results->replies,
        );
    }

    /**
     * The group total of the companies; all zeros without any.
     *
     * @param  list<self>  $companies
     */
    public static function sum(array $companies): self
    {
        $add = fn (string $count): int => array_sum(array_map(fn (self $totals): int => $totals->{$count}, $companies));

        return new self(
            company: null,
            runs: $add('runs'),
            sent: $add('sent'),
            bounced: $add('bounced'),
            withoutEmail: $add('withoutEmail'),
            failed: $add('failed'),
            replies: $add('replies'),
        );
    }

    /**
     * Whether any run was scheduled in the period.
     */
    public function hasRuns(): bool
    {
        return $this->runs > 0;
    }

    /**
     * Clients whose mail was sent and did not bounce.
     */
    public function delivered(): int
    {
        return max(0, $this->sent - $this->bounced);
    }

    /**
     * Bounces per hundred clients mailed; null when nothing was sent.
     */
    public function bounceRate(): ?float
    {
        return $this->sent === 0 ? null : $this->bounced / $this->sent * 100;
    }

    /**
     * Replies per hundred clients mailed; null when nothing was sent.
     */
    public function replyRate(): ?float
    {
        return $this->sent === 0 ? null : $this->replies / $this->sent * 100;
    }
}
