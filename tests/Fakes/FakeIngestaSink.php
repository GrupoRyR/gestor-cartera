<?php

namespace Tests\Fakes;

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
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Tests\Fakes\Concerns\QueriesInMemory;

/**
 * In-memory IngestaSink.
 *
 * Accepts every batch with a batch id (failNextWith() rejects the next one),
 * is idempotent by batch id, sums "saldo" for cartera loads and dispatches
 * BatchAccepted with event(), so listeners of Core and Comisiones can be
 * tested against it. A rejected batch id may be sent again.
 */
final class FakeIngestaSink implements IngestaSink
{
    use QueriesInMemory;

    /**
     * @var array<int, LoadRecord>
     */
    private array $loads = [];

    /**
     * @var array<int, list<array<string, scalar|null>>>
     */
    private array $rows = [];

    /**
     * Load id of every accepted batch id.
     *
     * @var array<string, int>
     */
    private array $accepted = [];

    /**
     * @var list<IncomingBatch>
     */
    private array $received = [];

    private ?InvalidBatch $failure = null;

    private int $nextId = 1;

    /**
     * Rejects the next batch with this exception.
     */
    public function failNextWith(InvalidBatch $exception): static
    {
        $this->failure = $exception;

        return $this;
    }

    public function receive(IncomingBatch $batch): LoadRecord
    {
        $this->received[] = $batch;

        if (isset($this->accepted[$batch->batchId])) {
            return $this->loads[$this->accepted[$batch->batchId]];
        }

        $failure = $this->failure;
        $this->failure = null;

        if ($failure === null && trim($batch->batchId) === '') {
            $failure = new InvalidBatch('The batch id is required.', ['batch_id' => ['The batch id is required.']]);
        }

        if ($failure !== null) {
            $this->store($batch, LoadStatus::Rejected, $failure->getMessage());

            throw $failure;
        }

        $load = $this->store($batch, LoadStatus::Accepted);
        $this->rows[$load->id] = $batch->rows;
        $this->accepted[$batch->batchId] = $load->id;

        event(new BatchAccepted($load));

        return $load;
    }

    public function rows(int $loadId): iterable
    {
        if (! isset($this->loads[$loadId])) {
            throw RecordNotFound::for('load', $loadId);
        }

        return $this->rows[$loadId] ?? [];
    }

    public function latest(Dataset $dataset, CompanyCode $company): ?LoadRecord
    {
        $latest = null;

        foreach ($this->loads as $load) {
            if ($load->status !== LoadStatus::Accepted || $load->dataset !== $dataset || $load->company !== $company) {
                continue;
            }

            if ($latest === null
                || [$load->cutoffDate, $load->receivedAt, $load->id] > [$latest->cutoffDate, $latest->receivedAt, $latest->id]) {
                $latest = $load;
            }
        }

        return $latest;
    }

    public function history(?Dataset $dataset = null, ?CompanyCode $company = null, int $limit = 30): array
    {
        $this->guardAtLeastOne($limit, 'limit');

        $loads = array_filter(
            $this->loads,
            fn (LoadRecord $load): bool => ($dataset === null || $load->dataset === $dataset)
                && ($company === null || $load->company === $company),
        );

        usort($loads, fn (LoadRecord $a, LoadRecord $b): int => [$b->receivedAt, $b->id] <=> [$a->receivedAt, $a->id]);

        return array_slice($loads, 0, $limit);
    }

    /**
     * Every batch handed to receive(), repeated and rejected ones included.
     *
     * @return list<IncomingBatch>
     */
    public function received(): array
    {
        return $this->received;
    }

    public function assertReceived(Dataset $dataset, ?CompanyCode $company = null): void
    {
        $matching = array_filter(
            $this->received,
            fn (IncomingBatch $batch): bool => $batch->dataset === $dataset && ($company === null || $batch->company === $company),
        );

        Assert::assertNotEmpty(
            $matching,
            "No {$dataset->value} batch was received".($company === null ? '.' : " for {$company->value}."),
        );
    }

    public function assertNothingReceived(): void
    {
        Assert::assertSame([], $this->received, count($this->received).' batches were received unexpectedly.');
    }

    private function store(IncomingBatch $batch, LoadStatus $status, ?string $error = null): LoadRecord
    {
        [$total, $overdue] = $status === LoadStatus::Accepted && $batch->dataset === Dataset::Receivables
            ? $this->carteraTotals($batch->rows)
            : [null, null];

        $load = new LoadRecord(
            id: $this->nextId++,
            dataset: $batch->dataset,
            company: $batch->company,
            cutoffDate: $batch->cutoffDate,
            source: $batch->source,
            status: $status,
            rowCount: count($batch->rows),
            total: $total,
            overdue: $overdue,
            receivedAt: CarbonImmutable::now(),
            receivedBy: $batch->receivedBy === null
                ? null
                : new UserRef($batch->receivedBy, "Usuario {$batch->receivedBy}", "usuario{$batch->receivedBy}@example.test"),
            error: $error,
        );

        $this->loads[$load->id] = $load;

        return $load;
    }

    /**
     * Sum of "saldo", and of "saldo" where "dias" > 0.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return array{float, float}
     */
    private function carteraTotals(array $rows): array
    {
        $total = 0.0;
        $overdue = 0.0;

        foreach ($rows as $row) {
            $balance = (float) ($row['saldo'] ?? 0);
            $total += $balance;
            $overdue += (int) ($row['dias'] ?? 0) > 0 ? $balance : 0.0;
        }

        return [$total, $overdue];
    }
}
