<?php

namespace App\Modules\Core\Application\Portfolio;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Recomputes the figures and the automatic status cached on every client of
 * a company from one snapshot, with the company's current parameters.
 *
 * Used by the robot import after each cartera snapshot; the daily commitment
 * task and the parameters screen reuse it.
 */
final class RecomputeClientStatuses
{
    /** Clients written per statement. */
    private const int CHUNK = 500;

    public function __construct(
        private readonly CompanyCatalog $companies,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Recomputes every client of $company, soft-deleted ones included, from
     * the snapshot $portfolioLoadId or, when null, from the company's latest
     * snapshot (latest cutoff date; the last imported one on a tie):
     *
     * - balance: all its items, credit notes included;
     * - overdue: the items past due that still owe money;
     * - max_days_overdue: the most days overdue of an item owing more than
     *   the tolerance (0 when none owes);
     * - status: ClientStatusRules::resolve() from the current status.
     *
     * A client missing from the snapshot owes nothing. Only clients whose
     * values change are written; each status change sets status_changed_at
     * and is audited as core.client.status_changed, except for the clients
     * in $newClientIds: an import just created them, so their first status
     * is their initial state, not a change. Does nothing while the company
     * has no snapshot.
     *
     * @param  list<int>  $newClientIds  Clients created by the import being recomputed.
     *
     * @throws ModelNotFoundException When $portfolioLoadId is not a snapshot of $company.
     */
    public function forCompany(CompanyCode $company, ?int $portfolioLoadId = null, array $newClientIds = []): void
    {
        $snapshot = $this->snapshot($company, $portfolioLoadId);

        if ($snapshot === null) {
            return;
        }

        $parameters = $this->companies->parameters($company);
        $positions = $this->positions($snapshot);
        $newClients = array_flip($newClientIds);
        $now = now();
        $rows = [];
        $statusChanges = [];

        $clients = ClientRecord::withTrashed()
            ->where('company', $company)
            ->orderBy('id')
            ->get(['id', 'company', 'code', 'name', 'tax_id', 'status', 'status_changed_at', 'balance', 'overdue', 'max_days_overdue']);

        foreach ($clients as $client) {
            $position = $positions[$client->id] ?? new ClientPosition;
            $status = ClientStatusRules::resolve($client->status, $position->items(), $parameters);
            $statusChanged = $status !== $client->status;

            if (! $statusChanged
                && Cents::fromDecimal($client->balance) === $position->balance()
                && Cents::fromDecimal($client->overdue) === $position->overdue()
                && $client->max_days_overdue === $position->maxDaysOverdue()) {
                continue;
            }

            $rows[] = [
                // The insert half of an upsert needs every NOT NULL column,
                // although these rows always exist and only get updated.
                'company' => $company->value,
                'code' => $client->code,
                'name' => $client->name,
                'tax_id' => $client->tax_id,
                'status' => $status->value,
                'status_changed_at' => $statusChanged ? $now : $client->status_changed_at,
                'balance' => Cents::toDecimal($position->balance()),
                'overdue' => Cents::toDecimal($position->overdue()),
                'max_days_overdue' => $position->maxDaysOverdue(),
            ];

            if ($statusChanged && ! isset($newClients[$client->id])) {
                $statusChanges[$client->id] = ['old' => $client->status->value, 'new' => $status->value];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ClientRecord::query()->upsert(
                $chunk,
                ['company', 'code'],
                ['status', 'status_changed_at', 'balance', 'overdue', 'max_days_overdue'],
            );
        }

        foreach ($statusChanges as $clientId => $change) {
            $this->audit->record(
                'core.client.status_changed',
                'client',
                $clientId,
                ['status' => $change],
                ['load_id' => $snapshot->load_id, 'cutoff_date' => $snapshot->cutoff_date->toDateString()],
            );
        }
    }

    /**
     * @throws ModelNotFoundException
     */
    private function snapshot(CompanyCode $company, ?int $portfolioLoadId): ?PortfolioLoadRecord
    {
        $query = PortfolioLoadRecord::query()->where('company', $company);

        if ($portfolioLoadId !== null) {
            return $query->findOrFail($portfolioLoadId);
        }

        return $query->orderByDesc('cutoff_date')->orderByDesc('id')->first();
    }

    /**
     * The position of every client with items in the snapshot, read in one
     * query and added up in cents, so sums are exact on every engine.
     *
     * @return array<int, ClientPosition>
     */
    private function positions(PortfolioLoadRecord $snapshot): array
    {
        $positions = [];

        $items = InvoiceRecord::query()
            ->where('portfolio_load_id', $snapshot->id)
            ->toBase()
            ->select(['client_id', 'balance', 'days_overdue'])
            ->cursor();

        foreach ($items as $item) {
            $clientId = (int) $item->client_id;
            $positions[$clientId] ??= new ClientPosition;
            $positions[$clientId]->add(Cents::fromDecimal($item->balance), (int) $item->days_overdue);
        }

        return $positions;
    }
}
