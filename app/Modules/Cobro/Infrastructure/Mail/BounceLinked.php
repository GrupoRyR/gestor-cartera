<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;

/**
 * Internal Cobro event: the mailbox sync stored a bounce linked to one of
 * the sent mails, so the delivery report can mark that send as bounced.
 */
final readonly class BounceLinked
{
    public function __construct(
        public InboundMessage $bounce,
    ) {}
}
