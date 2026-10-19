<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * What a delivery failure report says: why the mail was not delivered and,
 * when it names one, the address that failed.
 */
final readonly class Bounce
{
    public function __construct(
        public string $reason,
        public ?string $recipient = null,
    ) {}
}
