<?php

namespace App\Modules\Comisiones\Infrastructure\Dashboard;

use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\Dataset;

/**
 * The state of one SAP dataset on the dashboard (§7.1): the last load the
 * module staged and the loads Ingesta rejected after it.
 */
final readonly class LoadStatusRow
{
    /**
     * @param  CommissionLoad|null  $last  Null when no load of the dataset was staged yet.
     * @param  list<LoadRecord>  $rejected  Newest first.
     */
    public function __construct(
        public Dataset $dataset,
        public ?CommissionLoad $last,
        public array $rejected,
    ) {}
}
