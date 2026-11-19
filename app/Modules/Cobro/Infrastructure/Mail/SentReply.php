<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use Carbon\CarbonImmutable;

/**
 * A reply ReplySender sent.
 */
final readonly class SentReply
{
    /**
     * @param  list<string>  $to  Addresses Graph sent it to, lowercase.
     */
    public function __construct(
        public string $mailbox,
        public array $to,
        public string $subject,
        public string $internetMessageId,
        public ?string $conversationId,
        public CarbonImmutable $sentAt,
    ) {}
}
