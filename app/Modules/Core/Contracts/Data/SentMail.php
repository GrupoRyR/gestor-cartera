<?php

namespace App\Modules\Core\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * Receipt of a mail accepted by Microsoft Graph. Callers store both ids to
 * join later replies and bounces with the original send.
 */
final readonly class SentMail
{
    /**
     * @param  string  $mailbox  Sender mailbox address.
     * @param  string  $internetMessageId  RFC 5322 Message-ID, angle brackets included.
     * @param  string|null  $conversationId  Graph conversation id, when Graph returned one.
     * @param  CarbonImmutable  $sentAt  Moment Graph accepted the mail.
     */
    public function __construct(
        public string $mailbox,
        public string $internetMessageId,
        public ?string $conversationId,
        public CarbonImmutable $sentAt,
    ) {}
}
