<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * A collection mail ready to send or preview.
 */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
    ) {}
}
