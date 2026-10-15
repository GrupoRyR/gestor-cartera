<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use Carbon\CarbonImmutable;

/**
 * A batch of rows handed to IngestaSink::receive(), from the robot or from a
 * manual upload.
 */
final readonly class IncomingBatch
{
    /**
     * @param  CarbonImmutable  $cutoffDate  Snapshot cutoff date (corte), date only, America/Bogota.
     * @param  string  $batchId  Idempotency key chosen by the sender and unique across datasets
     *                           and companies; repeating it returns the original load.
     * @param  list<array<string, scalar|null>>  $rows  One associative array per row, keyed by the
     *                                                  dataset's snake_case column names (see IngestaSink).
     * @param  int|null  $receivedBy  Platform user who uploaded a manual load; null for the robot.
     */
    public function __construct(
        public Dataset $dataset,
        public CompanyCode $company,
        public CarbonImmutable $cutoffDate,
        public LoadSource $source,
        public string $batchId,
        public array $rows,
        public ?int $receivedBy = null,
    ) {}
}
