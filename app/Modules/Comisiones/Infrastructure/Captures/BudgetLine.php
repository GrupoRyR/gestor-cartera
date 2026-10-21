<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

/**
 * A valid row of a budget template: the code, its budget as "123.45" and
 * its channel, if any.
 */
final readonly class BudgetLine
{
    public function __construct(
        public int $row,
        public int $code,
        public string $amount,
        public ?string $channel,
    ) {}
}
