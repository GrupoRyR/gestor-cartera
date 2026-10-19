<?php

namespace App\Modules\Asesores\Domain\Activity;

/**
 * A note ready for the log of a client: the line the log shows and, when
 * there is more to say, the whole text.
 */
final readonly class Note
{
    public function __construct(
        public string $summary,
        public ?string $details,
    ) {}
}
