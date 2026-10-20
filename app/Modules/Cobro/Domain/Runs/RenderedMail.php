<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * A collection mail ready to send or preview.
 */
final readonly class RenderedMail
{
    /**
     * @param  list<string>  $cc  Carbon copies; the sender drops any address already in To.
     */
    public function __construct(
        public string $subject,
        public string $html,
        public array $cc = [],
    ) {}
}
