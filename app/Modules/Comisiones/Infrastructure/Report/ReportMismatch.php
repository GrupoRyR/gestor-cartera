<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use RuntimeException;

/**
 * A collection report whose total differs from the figure of the
 * consolidated report (§8.2, §9.3): it is never stored.
 */
final class ReportMismatch extends RuntimeException
{
    public function __construct(
        public readonly CollectionReport $report,
    ) {
        parent::__construct(sprintf(
            'The collection report %s of %s v%d totals %s, but the consolidated report says %s.',
            $report->reference,
            $report->period->key(),
            $report->version,
            $report->total()->amount(),
            $report->expected->amount(),
        ));
    }
}
