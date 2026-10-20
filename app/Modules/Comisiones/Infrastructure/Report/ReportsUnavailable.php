<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Settlement\Settlement;
use RuntimeException;

/**
 * Reports were asked of a settlement version that is not closed (§6 step 6):
 * only a closed, frozen version produces PDFs and the payroll file.
 */
final class ReportsUnavailable extends RuntimeException
{
    public static function notClosed(Settlement $settlement): self
    {
        return new self(sprintf(
            'Version %d of %s is %s; only a closed version produces reports.',
            $settlement->version,
            $settlement->period->key(),
            $settlement->status->value,
        ));
    }
}
