<?php

namespace App\Modules\Gerencia\Domain\Mail;

/**
 * What the Cobro emails of a period produced: sends, replies and bounces.
 */
final readonly class MailResults
{
    /**
     * @param  bool  $truncated  The activity log was read up to its limit without reaching
     *                           the start of the period, so older entries may be missing.
     */
    public function __construct(
        public int $sent,
        public int $replies,
        public int $bounces,
        public bool $truncated,
    ) {}

    /**
     * Emails that did not bounce.
     */
    public function delivered(): int
    {
        return max(0, $this->sent - $this->bounces);
    }

    /**
     * Bounces per hundred emails sent; null when nothing was sent.
     */
    public function bounceRate(): ?float
    {
        return $this->sent === 0 ? null : $this->bounces / $this->sent * 100;
    }

    /**
     * Replies per hundred emails sent; null when nothing was sent.
     */
    public function replyRate(): ?float
    {
        return $this->sent === 0 ? null : $this->replies / $this->sent * 100;
    }
}
