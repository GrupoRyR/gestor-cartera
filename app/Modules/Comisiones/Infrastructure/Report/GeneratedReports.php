<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;

/**
 * What a batch generation did (ReportArchive::generateMissing): the reports
 * it stored and the ones it refused because their total did not match the
 * consolidated report (§9.3).
 */
final readonly class GeneratedReports
{
    /**
     * @param  list<DocumentRecord>  $generated
     * @param  list<CollectionReport>  $mismatched
     */
    public function __construct(
        public array $generated,
        public array $mismatched,
    ) {}
}
