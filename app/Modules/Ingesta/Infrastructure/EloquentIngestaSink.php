<?php

namespace App\Modules\Ingesta\Infrastructure;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\BatchValidator;
use App\Modules\Ingesta\Domain\Cents;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoadChunk;
use App\Modules\Ingesta\Infrastructure\Storage\ChunkCodec;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * IngestaSink on the database: every batch becomes an entry of
 * ingesta_loads; an accepted one keeps its rows as a dated snapshot in
 * ingesta_load_chunks (ChunkCodec), in the dataset's column order, and is
 * never overwritten.
 *
 * Idempotency rests on the unique accepted_batch_id: a repeated batch id,
 * also from a concurrent request, returns the accepted load without
 * dispatching again. BatchAccepted is dispatched after the transaction
 * commits, so the queued listeners of Core and Comisiones (the database
 * queue does not wait for commits) always find the rows.
 */
final class EloquentIngestaSink implements IngestaSink
{
    public function __construct(
        private readonly BatchValidator $validator,
        private readonly DatabaseManager $db,
    ) {}

    public function receive(IncomingBatch $batch): LoadRecord
    {
        $existing = $this->findAccepted($batch->batchId);

        if ($existing !== null) {
            return $existing;
        }

        $errors = $this->validator->errors($batch);

        if ($errors !== []) {
            $this->storeRejected($batch, $errors);

            throw new InvalidBatch(BatchValidator::summarize($errors), $errors);
        }

        try {
            $load = $this->db->connection()->transaction(fn (): IngestaLoad => $this->storeAccepted($batch));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent copy of the batch was accepted first: it wins.
            return $this->findAccepted($batch->batchId) ?? throw $e;
        }

        $record = $this->toRecord($load);

        $this->db->connection()->afterCommit(fn () => BatchAccepted::dispatch($record));

        return $record;
    }

    public function rows(int $loadId): iterable
    {
        $load = IngestaLoad::query()->find($loadId) ?? throw RecordNotFound::for('load', $loadId);

        if ($load->status !== LoadStatus::Accepted) {
            return [];
        }

        return $this->readChunks($load->id, $load->columns ?? []);
    }

    public function latest(Dataset $dataset, CompanyCode $company): ?LoadRecord
    {
        $load = $this->query()
            ->where('dataset', $dataset)
            ->where('company', $company)
            ->where('status', LoadStatus::Accepted)
            ->orderByDesc('cutoff_date')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        return $load === null ? null : $this->toRecord($load);
    }

    public function history(?Dataset $dataset = null, ?CompanyCode $company = null, int $limit = 30): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, {$limit} given.");
        }

        return $this->query()
            ->when($dataset !== null, fn (Builder $query) => $query->where('dataset', $dataset))
            ->when($company !== null, fn (Builder $query) => $query->where('company', $company))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (IngestaLoad $load): LoadRecord => $this->toRecord($load))
            ->values()
            ->all();
    }

    /**
     * @return Builder<IngestaLoad>
     */
    private function query(): Builder
    {
        return IngestaLoad::query()->with('receivedBy');
    }

    private function findAccepted(string $batchId): ?LoadRecord
    {
        $load = $this->query()->where('accepted_batch_id', $batchId)->first();

        return $load === null ? null : $this->toRecord($load);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function storeRejected(IncomingBatch $batch, array $errors): void
    {
        IngestaLoad::query()->create($this->attributes($batch) + [
            // The column fits any id, also one rejected for being too long.
            'batch_id' => mb_substr($batch->batchId, 0, BatchValidator::MAX_BATCH_ID_LENGTH),
            'status' => LoadStatus::Rejected,
            'error' => BatchValidator::summarize($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * The accepted load and its chunks; the caller wraps it in a transaction.
     */
    private function storeAccepted(IncomingBatch $batch): IngestaLoad
    {
        $columns = DatasetSchemas::for($batch->dataset)->columnNames();
        [$total, $overdue] = $batch->dataset === Dataset::Receivables ? $this->carteraTotals($batch->rows) : [null, null];

        $load = IngestaLoad::query()->create($this->attributes($batch) + [
            'batch_id' => $batch->batchId,
            'accepted_batch_id' => $batch->batchId,
            'status' => LoadStatus::Accepted,
            'total' => $total === null ? null : Cents::toDecimal($total),
            'overdue' => $overdue === null ? null : Cents::toDecimal($overdue),
            'columns' => $columns,
        ]);

        foreach (array_chunk($batch->rows, ChunkCodec::ROWS) as $sequence => $rows) {
            // Values in the schema's column order; the validator checked every row has them all.
            $values = array_map(fn (array $row): array => array_map(fn (string $column): mixed => $row[$column], $columns), $rows);

            IngestaLoadChunk::query()->create([
                'load_id' => $load->id,
                'sequence' => $sequence,
                'row_count' => count($rows),
                'payload' => ChunkCodec::encode($values),
            ]);
        }

        return $load;
    }

    /**
     * The columns every load shares, accepted or rejected.
     *
     * @return array<string, mixed>
     */
    private function attributes(IncomingBatch $batch): array
    {
        return [
            'dataset' => $batch->dataset,
            'company' => $batch->company,
            'cutoff_date' => $batch->cutoffDate->format('Y-m-d'),
            'source' => $batch->source,
            'row_count' => count($batch->rows),
            'received_at' => CarbonImmutable::now(),
            'received_by' => $batch->receivedBy,
        ];
    }

    /**
     * Sum of "saldo", and of "saldo" where "dias" > 0, in cents. The
     * validator already checked both are present and well formed.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return array{int, int}
     */
    private function carteraTotals(array $rows): array
    {
        $total = 0;
        $overdue = 0;

        foreach ($rows as $row) {
            $balance = Cents::fromDecimal((string) $row['saldo']);
            $total = Cents::add($total, $balance);
            $overdue = (int) $row['dias'] > 0 ? Cents::add($overdue, $balance) : $overdue;
        }

        return [$total, $overdue];
    }

    /**
     * The rows of an accepted load, one chunk per query, so memory holds a
     * single chunk at a time.
     *
     * @param  list<string>  $columns
     * @return Generator<int, array<string, scalar|null>>
     *
     * @throws UnexpectedValueException When a chunk is corrupt or does not match the columns.
     */
    private function readChunks(int $loadId, array $columns): Generator
    {
        $chunkIds = IngestaLoadChunk::query()->where('load_id', $loadId)->orderBy('sequence')->pluck('id');
        $index = 0;

        foreach ($chunkIds as $chunkId) {
            $payload = (string) IngestaLoadChunk::query()->whereKey($chunkId)->value('payload');

            foreach (ChunkCodec::decode($payload) as $values) {
                if (count($values) !== count($columns)) {
                    throw new UnexpectedValueException("A chunk of load {$loadId} does not match its columns.");
                }

                yield $index++ => array_combine($columns, $values);
            }
        }
    }

    private function toRecord(IngestaLoad $load): LoadRecord
    {
        $user = $load->receivedBy;

        return new LoadRecord(
            id: $load->id,
            dataset: $load->dataset,
            company: $load->company,
            cutoffDate: $load->cutoff_date,
            source: $load->source,
            status: $load->status,
            rowCount: $load->row_count,
            total: $load->total === null ? null : Cents::toFloat(Cents::fromDecimal($load->total)),
            overdue: $load->overdue === null ? null : Cents::toFloat(Cents::fromDecimal($load->overdue)),
            receivedAt: $load->received_at,
            receivedBy: $user === null ? null : new UserRef($user->id, $user->name, $user->email),
            error: $load->error,
        );
    }
}
