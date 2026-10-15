<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * A reply or bounce read from a company mailbox.
 */
final readonly class InboundMail
{
    /**
     * @param  string  $id  Stable id of the message inside the platform (opaque string).
     * @param  CompanyCode  $company  Company whose mailbox received it.
     * @param  int|null  $clientId  Client it was linked to; null when it could not be linked.
     * @param  string  $from  Sender address.
     * @param  string  $preview  Plain-text excerpt of the body.
     * @param  bool  $isBounce  A delivery failure report instead of a human reply.
     * @param  string|null  $bounceReason  Reason read from the bounce, when $isBounce.
     * @param  string|null  $conversationId  Graph conversation id, to join it with the send.
     * @param  bool  $read  Already marked as read in the platform.
     * @param  string|null  $url  Link to the message on the Cobro screen.
     */
    public function __construct(
        public string $id,
        public CompanyCode $company,
        public ?int $clientId,
        public string $from,
        public string $subject,
        public string $preview,
        public CarbonImmutable $receivedAt,
        public bool $isBounce = false,
        public ?string $bounceReason = null,
        public ?string $conversationId = null,
        public bool $read = false,
        public ?string $url = null,
    ) {}
}
