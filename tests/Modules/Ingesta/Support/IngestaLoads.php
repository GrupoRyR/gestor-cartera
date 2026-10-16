<?php

namespace Tests\Modules\Ingesta\Support;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;

/**
 * Entries of the load history written straight to ingesta_loads, without
 * rows: enough for the screens and the alert, which read only the history.
 */
final class IngestaLoads
{
    private static int $sequence = 0;

    /**
     * An accepted robot cartera load of SGX cut and received now, with the
     * given attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function accepted(array $attributes = []): IngestaLoad
    {
        $batchId = $attributes['batch_id'] ?? 'lote-'.++self::$sequence;

        return IngestaLoad::query()->create(array_merge([
            'batch_id' => $batchId,
            'accepted_batch_id' => $batchId,
            'dataset' => Dataset::Receivables,
            'company' => CompanyCode::SGX,
            'cutoff_date' => CarbonImmutable::now()->format('Y-m-d'),
            'source' => LoadSource::Robot,
            'status' => LoadStatus::Accepted,
            'row_count' => 1200,
            'total' => '1250000000.50',
            'overdue' => '300000000.00',
            'columns' => [],
            'received_at' => CarbonImmutable::now(),
        ], $attributes));
    }

    /**
     * A rejected load with its summary and errors per field.
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $attributes
     */
    public static function rejected(array $errors, array $attributes = []): IngestaLoad
    {
        return self::accepted(array_merge([
            'accepted_batch_id' => null,
            'status' => LoadStatus::Rejected,
            'total' => null,
            'overdue' => null,
            'columns' => null,
            'error' => count($errors) === 1 ? array_values($errors)[0][0] : 'El lote tiene '.count($errors).' errores.',
            'errors' => $errors,
        ], $attributes));
    }
}
