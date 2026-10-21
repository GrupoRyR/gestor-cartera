<?php

namespace App\Modules\Comisiones\Infrastructure\Dashboard;

use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\IngestaSink;

/**
 * The SAP loads of the Comisiones datasets (§7.1, "estado de las cargas
 * SAP: hora, filas, errores"): per dataset, the last load staged in
 * comisiones_cargas and the loads of SGX that Ingesta rejected after it.
 */
final class LoadBoard
{
    /** The datasets the module stages, in the order the dashboard lists them. */
    public const array DATASETS = [
        Dataset::Sales,
        Dataset::Collections,
        Dataset::Offsets,
        Dataset::CustomerMaster,
        Dataset::SalesRepMaster,
    ];

    /** How far back the rejected loads are looked for, per dataset. */
    private const int HISTORY = 30;

    public function __construct(
        private readonly IngestaSink $ingesta,
    ) {}

    /**
     * @return list<LoadStatusRow>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (self::DATASETS as $dataset) {
            $last = CommissionLoad::query()
                ->where('dataset', $dataset->value)
                ->orderByDesc('recibida_en')
                ->orderByDesc('load_id')
                ->first();

            // Ingesta numbers its loads in order, so a rejection after the last
            // staged load has a higher id.
            $rejected = array_values(array_filter(
                $this->ingesta->history($dataset, CompanyCode::SGX, self::HISTORY),
                fn (LoadRecord $load): bool => $load->status === LoadStatus::Rejected && ($last === null || $load->id > $last->load_id),
            ));

            $rows[] = new LoadStatusRow($dataset, $last, $rejected);
        }

        return $rows;
    }
}
