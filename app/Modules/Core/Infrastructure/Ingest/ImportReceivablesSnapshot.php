<?php

namespace App\Modules\Core\Infrastructure\Ingest;

use App\Modules\Core\Application\Portfolio\Cents;
use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;

/**
 * Imports a cartera batch accepted by Ingesta as a receivables snapshot, in
 * one transaction:
 *
 * 1. the snapshot (portfolio_loads) with its count and totals;
 * 2. the clients of the batch, created or (from the company's latest
 *    snapshot) refreshed, soft-deleted ones included and never restored;
 * 3. the invoices, in chunks;
 * 4. the cached balance and status of every client of the company
 *    (RecomputeClientStatuses).
 *
 * Idempotent: a load that already has its snapshot is skipped. The rows are
 * read twice (clients and totals, then invoices), so memory stays flat.
 */
final class ImportReceivablesSnapshot implements ShouldQueue
{
    /** Seconds a large snapshot may take before the worker stops it. */
    public int $timeout = 300;

    /** A failed import is rolled back whole and kept in failed_jobs. */
    public int $tries = 1;

    /** Rows written per statement. */
    private const int CHUNK = 500;

    /** The SAP data an import refreshes on an existing client. */
    private const array CLIENT_DATA = ['name', 'tax_id', 'group', 'city', 'payment_terms', 'credit_limit', 'advisor_code'];

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly RecomputeClientStatuses $statuses,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Only cartera batches are queued.
     */
    public function shouldQueue(BatchAccepted $event): bool
    {
        return $event->load->dataset === Dataset::Receivables;
    }

    public function handle(BatchAccepted $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $load = $event->load;

        $this->db->transaction(function () use ($load): void {
            if (PortfolioLoadRecord::query()->where('load_id', $load->id)->exists()) {
                return;
            }

            [$snapshot, $clients] = $this->createSnapshot($load);
            $clientIds = $this->saveClients($load->company, $clients, refresh: $this->isLatest($snapshot));
            $this->insertInvoices($load, $snapshot, $clientIds);
            $this->statuses->forCompany($load->company);
        });
    }

    /**
     * First pass: the snapshot with its count, total and overdue (the
     * balance of the items past due on the cutoff date), and the data of
     * each client (its last row wins).
     *
     * @return array{PortfolioLoadRecord, list<array<string, scalar|null>>}
     */
    private function createSnapshot(LoadRecord $load): array
    {
        $clients = [];
        $count = 0;
        $total = 0;
        $overdue = 0;

        foreach ($this->sink->rows($load->id) as $row) {
            $item = ReceivablesRow::read($row, $load->cutoffDate);
            $clients[$item->clientCode()] = ['company' => $load->company->value] + $item->client;
            $count++;
            $total += $item->balanceCents;
            $overdue += $item->daysOverdue > 0 ? $item->balanceCents : 0;
        }

        $snapshot = PortfolioLoadRecord::query()->create([
            'company' => $load->company,
            'cutoff_date' => $load->cutoffDate->startOfDay(),
            'load_id' => $load->id,
            'source' => $load->source,
            'invoice_count' => $count,
            'total' => Cents::toDecimal($total),
            'overdue' => Cents::toDecimal($overdue),
        ]);

        return [$snapshot, array_values($clients)];
    }

    /**
     * Whether no snapshot of the company has a later cutoff date. An older
     * snapshot that arrives late is kept as history but does not refresh
     * the clients' SAP data.
     */
    private function isLatest(PortfolioLoadRecord $snapshot): bool
    {
        return ! PortfolioLoadRecord::query()
            ->where('company', $snapshot->company)
            ->where('cutoff_date', '>', $snapshot->cutoff_date)
            ->exists();
    }

    /**
     * Creates the clients new to the company and, with $refresh, updates the
     * SAP data of the existing ones by (company, code), soft-deleted ones
     * too: an import never restores a client a user removed.
     *
     * @param  list<array<string, scalar|null>>  $clients
     * @return array<int|string, int> Client ids by SAP code.
     */
    private function saveClients(CompanyCode $company, array $clients, bool $refresh): array
    {
        if (! $refresh) {
            $existing = $this->clientIds($company);
            $clients = array_values(array_filter($clients, fn (array $client): bool => ! isset($existing[(string) $client['code']])));
        }

        foreach (array_chunk($clients, self::CHUNK) as $chunk) {
            ClientRecord::query()->upsert($chunk, ['company', 'code'], self::CLIENT_DATA);
        }

        return $this->clientIds($company);
    }

    /**
     * @return array<int|string, int>
     */
    private function clientIds(CompanyCode $company): array
    {
        return ClientRecord::withTrashed()->where('company', $company)->pluck('id', 'code')->all();
    }

    /**
     * Second pass: the invoices of the snapshot, a chunk per statement.
     *
     * @param  array<int|string, int>  $clientIds
     */
    private function insertInvoices(LoadRecord $load, PortfolioLoadRecord $snapshot, array $clientIds): void
    {
        $chunk = [];

        foreach ($this->sink->rows($load->id) as $row) {
            $item = ReceivablesRow::read($row, $load->cutoffDate);
            $chunk[] = [
                'portfolio_load_id' => $snapshot->id,
                'company' => $load->company->value,
                'client_id' => $clientIds[$item->clientCode()],
            ] + $item->invoice;

            if (count($chunk) === self::CHUNK) {
                InvoiceRecord::query()->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            InvoiceRecord::query()->insert($chunk);
        }
    }
}
