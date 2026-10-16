<?php

namespace App\Modules\Core\Application\Portfolio;

use App\Modules\Core\Application\Activity\StatusChangeSummary;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Recomputes the figures and the automatic status cached on every client of
 * a company from one snapshot, with the company's current parameters.
 *
 * Used by the robot import after each cartera snapshot; the parameters screen
 * will reuse it. The commitment workflow reads the automatic status of one
 * client with automaticStatus().
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
     * values change are written; each status change sets status_changed_at,
     * is audited as core.client.status_changed and leaves a system entry in
     * the client's log, except for the clients in $newClientIds: an import
     * just created them, so their first status is their initial state, not a
     * change. Does nothing while the company has no snapshot.
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
                $statusChanges[$client->id] = [$client->status, $status];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            ClientRecord::query()->upsert(
                $chunk,
                ['company', 'code'],
                ['status', 'status_changed_at', 'balance', 'overdue', 'max_days_overdue'],
            );
        }

        foreach ($statusChanges as $clientId => [$old, $new]) {
            $this->audit->record(
                'core.client.status_changed',
                'client',
                $clientId,
                ['status' => ['old' => $old->value, 'new' => $new->value]],
                ['load_id' => $snapshot->load_id, 'cutoff_date' => $snapshot->cutoff_date->toDateString()],
            );
        }

        $this->logStatusChanges($company, $statusChanges, $snapshot, $now);
    }

    /**
     * The automatic status of one client from the latest snapshot of its
     * company, ignoring the manual status it has now: what the commitment
     * workflow hands a client back to (StatusDecision::recompute()).
     *
     * Resolved as from a manual status, so a client that no longer owes
     * anything (or is missing from the snapshot, or whose company has no
     * snapshot yet) becomes PaymentReceived, and one that owes gets
     * Overdue, DueSoon or Current. Reads only; the caller saves it.
     */
    public function automaticStatus(ClientRecord $client): ClientStatus
    {
        $snapshot = $this->snapshot($client->company, null);
        $positions = $snapshot === null ? [] : $this->positions($snapshot, $client->id);
        $position = $positions[$client->id] ?? new ClientPosition;

        // Any automatic status other than Current: Current would stay Current on a zero balance.
        return ClientStatusRules::resolve(ClientStatus::Overdue, $position->items(), $this->companies->parameters($client->company));
    }

    /**
     * One system entry (ActivityType::StatusChange) per status change in the
     * log of its client, inserted CHUNK rows per statement. A bulk insert
     * skips the model, so the metadata goes JSON-encoded and the timestamps
     * are explicit. System entries never move the client's last activity.
     *
     * @param  array<int, array{ClientStatus, ClientStatus}>  $statusChanges  Old and new status by client id.
     */
    private function logStatusChanges(CompanyCode $company, array $statusChanges, PortfolioLoadRecord $snapshot, DateTimeInterface $now): void
    {
        $entries = [];

        foreach ($statusChanges as $clientId => [$old, $new]) {
            $entries[] = [
                'company' => $company->value,
                'client_id' => $clientId,
                'type' => ActivityType::StatusChange->value,
                'summary' => StatusChangeSummary::automatic($old, $new),
                'user_id' => null,
                'occurred_at' => $now,
                'metadata' => json_encode(['from' => $old->value, 'to' => $new->value, 'load_id' => $snapshot->load_id], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($entries, self::CHUNK) as $chunk) {
            ActivityRecord::query()->insert($chunk);
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
     * The position of every client with items in the snapshot (or only of
     * $clientId), read in one query and added up in cents, so sums are exact
     * on every engine.
     *
     * @return array<int, ClientPosition>
     */
    private function positions(PortfolioLoadRecord $snapshot, ?int $clientId = null): array
    {
        $positions = [];

        $items = InvoiceRecord::query()
            ->where('portfolio_load_id', $snapshot->id)
            ->when($clientId !== null, fn ($items) => $items->where('client_id', $clientId))
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
