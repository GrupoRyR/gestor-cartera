<?php

namespace App\Modules\Comisiones\Infrastructure\Ingest;

use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\PaymentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SaleRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SellerRecord;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Stages a Comisiones batch accepted by Ingesta (SGX only): ventas,
 * recaudos and cruces by their natural key, the client master (with the
 * history of each client's seller) and the seller master. Each load leaves
 * one row in comisiones_cargas with its counts and a comisiones.load.imported
 * event in the audit trail (by the system).
 *
 * Idempotent: the same rows again leave the same staging rows; a row that
 * comes back with new values replaces the staged one.
 */
final class ImportCommissionBatch implements ShouldQueue
{
    /** Rows written per statement. */
    private const int CHUNK = 500;

    private const array DATASETS = [
        Dataset::Sales,
        Dataset::Collections,
        Dataset::Offsets,
        Dataset::CustomerMaster,
        Dataset::SalesRepMaster,
    ];

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly ConnectionInterface $db,
        private readonly AssignmentHistory $assignments,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Only the Comisiones datasets of SGX are queued.
     */
    public function shouldQueue(BatchAccepted $event): bool
    {
        return $event->load->company === CompanyCode::SGX
            && in_array($event->load->dataset, self::DATASETS, true);
    }

    public function handle(BatchAccepted $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $load = $event->load;

        $this->db->transaction(function () use ($load): void {
            [$rows, $inserted] = match ($load->dataset) {
                Dataset::Sales => $this->stage($load, StagingRows::sale(...), SaleRecord::class, 'clave'),
                Dataset::Collections => $this->stage($load, StagingRows::payment(...), PaymentRecord::class, 'clave'),
                Dataset::Offsets => $this->stage($load, StagingRows::crossing(...), CrossingRecord::class, 'clave'),
                Dataset::CustomerMaster => $this->stage($load, StagingRows::client(...), ClientRecord::class, 'codigo_cliente', $this->recordAssignments(...)),
                default => $this->stage($load, StagingRows::seller(...), SellerRecord::class, 'slp_code'),
            };

            $counts = [
                'dataset' => $load->dataset->value,
                'load_id' => $load->id,
                'corte' => $load->cutoffDate->format('Y-m-d'),
                'filas' => $rows,
                'insertadas' => $inserted,
                'actualizadas' => $rows - $inserted,
            ];

            $staged = CommissionLoad::query()->updateOrCreate(['load_id' => $load->id], [
                ...$counts,
                'fuente' => $load->source->value,
                'recibida_en' => $load->receivedAt,
                'estado' => CommissionLoad::ACCEPTED,
            ]);

            $this->audit->record('comisiones.load.imported', 'comisiones_carga', $staged->id, context: $counts);
        });
    }

    /**
     * Upserts the rows of the load by $key, in chunks.
     *
     * @param  Closure(array<string, scalar|null>, int): array<string, scalar|null>  $map
     * @param  class-string<Model>  $model
     * @param  (Closure(list<array<string, scalar|null>>, LoadRecord): void)|null  $after  Runs on each chunk once it is staged.
     * @return array{int, int} Rows staged and rows that were new.
     */
    private function stage(LoadRecord $load, Closure $map, string $model, string $key, ?Closure $after = null): array
    {
        $rows = 0;
        $inserted = 0;
        $chunk = [];

        foreach ($this->sink->rows($load->id) as $row) {
            $record = $map($row, $load->id);
            // A key repeated in the batch is staged once: the last row wins.
            $chunk[(string) $record[$key]] = $record;

            if (count($chunk) >= self::CHUNK) {
                $inserted += $this->upsert($chunk, $model, $key, $load, $after);
                $rows += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $inserted += $this->upsert($chunk, $model, $key, $load, $after);
            $rows += count($chunk);
        }

        return [$rows, $inserted];
    }

    /**
     * Writes one chunk and says how many of its rows were new.
     *
     * @param  non-empty-array<string, array<string, scalar|null>>  $chunk  Keyed by $key.
     * @param  class-string<Model>  $model
     * @param  (Closure(list<array<string, scalar|null>>, LoadRecord): void)|null  $after
     */
    private function upsert(array $chunk, string $model, string $key, LoadRecord $load, ?Closure $after): int
    {
        $records = array_values($chunk);
        $existing = $model::query()->whereIn($key, array_keys($chunk))->count();
        $columns = array_values(array_diff(array_keys($records[0]), [$key]));

        $model::query()->upsert($records, [$key], $columns);

        if ($after !== null) {
            $after($records, $load);
        }

        return count($records) - $existing;
    }

    /**
     * @param  list<array<string, scalar|null>>  $clients
     */
    private function recordAssignments(array $clients, LoadRecord $load): void
    {
        $this->assignments->record($clients, $load->cutoffDate->format('Y-m-d'), $load->id);
    }
}
