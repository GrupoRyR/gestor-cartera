<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use Carbon\CarbonImmutable;

/**
 * One entry of the load history (historial de cargas).
 */
final readonly class LoadRecord
{
    /**
     * @param  int  $id  Load id; pass it to IngestaSink::rows().
     * @param  CarbonImmutable  $cutoffDate  Snapshot cutoff date (corte), date only, America/Bogota.
     * @param  int  $rowCount  Rows in the batch (for cartera, one per invoice installment).
     * @param  float|null  $total  Cartera loads: sum of "saldo" in Colombian pesos; null for other datasets.
     * @param  float|null  $overdue  Cartera loads: sum of "saldo" of rows with "dias" > 0; null for other datasets.
     * @param  CarbonImmutable  $receivedAt  Moment Ingesta received the batch.
     * @param  UserRef|null  $receivedBy  User who uploaded a manual load; null for the robot.
     * @param  string|null  $error  Why a rejected load failed; null when accepted.
     */
    public function __construct(
        public int $id,
        public Dataset $dataset,
        public CompanyCode $company,
        public CarbonImmutable $cutoffDate,
        public LoadSource $source,
        public LoadStatus $status,
        public int $rowCount,
        public ?float $total,
        public ?float $overdue,
        public CarbonImmutable $receivedAt,
        public ?UserRef $receivedBy = null,
        public ?string $error = null,
    ) {}
}
