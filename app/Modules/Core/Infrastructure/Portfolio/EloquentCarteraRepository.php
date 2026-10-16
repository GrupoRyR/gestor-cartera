<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use InvalidArgumentException;

/**
 * CarteraRepository over the Core tables, for the signed-in user.
 *
 * The scope (PortfolioScope) is read on every call from the authenticated
 * user and CurrentCompany: the companies of the active company or the group
 * view (or the query's companies the user may see) and, within them, the
 * clients of the user's roles; asSystem() drops the user. Soft-deleted
 * clients never appear. Lists sort and filter on the figures each client
 * caches from the latest snapshot of its company; invoices and totals come
 * from that snapshot (the latest cutoff date and, on a tie, the last one
 * imported).
 */
final class EloquentCarteraRepository implements CarteraRepository
{
    /**
     * Clients read per query while allClients() is iterated.
     */
    public const int CHUNK = 500;

    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly AuthFactory $auth,
        private readonly PortfolioMapper $mapper,
        private readonly SnapshotTotals $snapshotTotals,
        private readonly bool $system = false,
    ) {}

    public function asSystem(): CarteraRepository
    {
        return new self($this->currentCompany, $this->auth, $this->mapper, $this->snapshotTotals, system: true);
    }

    public function paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("The perPage must be at least 1, {$perPage} given.");
        }

        $page = ClientListing::sort($this->matching($query, $this->scope($query->companies)), $query)->paginate($perPage);

        return new Paginator(
            $this->mapper->summaries($page->items()),
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            $page->getOptions(),
        );
    }

    /**
     * Resolves the scope now and reads the clients CHUNK at a time while the
     * result is iterated, in query order (sorted to the id, so pages never
     * overlap).
     *
     * @return Generator<int, ClientSummary, mixed, void>
     */
    public function allClients(ClientQuery $query): iterable
    {
        return $this->lazily(ClientListing::sort($this->matching($query, $this->scope($query->companies)), $query));
    }

    public function client(int $clientId): ?ClientDetail
    {
        $client = $this->visibleClient($clientId);

        return $client === null ? null : $this->mapper->detail($client);
    }

    /**
     * Items of the client in the latest snapshot of its company that owe or
     * credit more than the balance tolerance, by due date and then id.
     */
    public function openInvoices(int $clientId): array
    {
        $client = $this->visibleClient($clientId);
        $snapshot = $client === null ? null : $this->latestSnapshot($client->company);

        if ($client === null || $snapshot === null) {
            return [];
        }

        $records = InvoiceRecord::query()
            ->where('portfolio_load_id', $snapshot->id)
            ->where('client_id', $client->id)
            ->whereRaw('ABS(balance) > '.number_format(ClientStatusRules::BALANCE_TOLERANCE, 2, '.', ''))
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $invoices = [];

        foreach ($records as $record) {
            $invoices[] = $this->mapper->invoice($record);
        }

        return $invoices;
    }

    /**
     * Sums the latest snapshot of each company in scope for the clients in
     * scope that match the query (its sort does not matter). Without
     * snapshots, or when the scope sees no client, the totals are empty;
     * with snapshots and no matching client, they are zero at the cutoff.
     */
    public function totals(?ClientQuery $query = null): PortfolioTotals
    {
        $query ??= new ClientQuery;
        $scope = $this->scope($query->companies);
        $snapshots = [];

        foreach ($scope->isEmpty() ? [] : $scope->companies as $company) {
            $snapshot = $this->latestSnapshot($company);

            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        if ($snapshots === []) {
            return PortfolioTotals::empty();
        }

        return $this->combine($snapshots, $this->sums($snapshots, $this->matching($query, $scope)));
    }

    /**
     * One entry per cutoff date with a snapshot in scope, combining the
     * companies that share the date; for each company and date, its last
     * load imported. Dates compare without the time of day.
     */
    public function history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array
    {
        if ($from->toDateString() > $to->toDateString()) {
            throw new InvalidArgumentException("The history range ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }

        $query ??= new ClientQuery;
        $scope = $this->scope($query->companies);

        if ($scope->isEmpty()) {
            return [];
        }

        $records = PortfolioLoadRecord::query()
            ->whereIn('company', array_map(fn (CompanyCode $code): string => $code->value, $scope->companies))
            ->whereDate('cutoff_date', '>=', $from->toDateString())
            ->whereDate('cutoff_date', '<=', $to->toDateString())
            ->orderBy('cutoff_date')
            ->orderBy('id')
            ->get(['id', 'company', 'cutoff_date']);

        // By date (ascending) and company; a later load of the same day replaces the earlier one.
        $byDate = [];

        foreach ($records as $record) {
            $byDate[$record->cutoff_date->toDateString()][$record->company->value] = $record;
        }

        $snapshots = [];

        foreach ($byDate as $ofDate) {
            $snapshots = [...$snapshots, ...array_values($ofDate)];
        }

        $sums = $this->sums($snapshots, $this->matching($query, $scope));
        $history = [];

        foreach ($byDate as $ofDate) {
            $history[] = $this->combine(array_values($ofDate), $sums);
        }

        return $history;
    }

    /**
     * The SAP advisors whose clients the scope sees, by name (without regard
     * to case), then company (CompanyCode order) and code.
     */
    public function advisors(): array
    {
        $records = ClientListing::orderByRank(
            $this->scope()->advisors(AdvisorRecord::query())->orderByRaw('LOWER(advisors.name) asc'),
            'advisors.company',
            array_map(fn (CompanyCode $code): string => $code->value, CompanyCode::cases()),
        )->orderBy('advisors.code')->get();

        $advisors = [];

        foreach ($records as $record) {
            $advisors[] = $this->mapper->advisor($record);
        }

        return $advisors;
    }

    /**
     * The scope of this repository: the signed-in user's, or the system's.
     *
     * @param  list<CompanyCode>  $requested  ClientQuery::$companies.
     */
    private function scope(array $requested = []): PortfolioScope
    {
        return $this->system
            ? PortfolioScope::system($requested)
            : PortfolioScope::forUser($this->auth->guard()->user(), $this->currentCompany, $requested);
    }

    /**
     * The clients of $scope that match the filters of $query, unsorted.
     *
     * @return Builder<ClientRecord>
     */
    private function matching(ClientQuery $query, PortfolioScope $scope): Builder
    {
        return ClientListing::filter($scope->clients(ClientRecord::query()), $query);
    }

    /**
     * The client, when it exists, is not deleted and is in scope.
     */
    private function visibleClient(int $clientId): ?ClientRecord
    {
        return $this->scope()->clients(ClientRecord::query())->whereKey($clientId)->first();
    }

    /**
     * The latest snapshot of $company: the latest cutoff date and, on a tie,
     * the last one imported.
     */
    private function latestSnapshot(CompanyCode $company): ?PortfolioLoadRecord
    {
        return PortfolioLoadRecord::query()
            ->where('company', $company)
            ->orderByDesc('cutoff_date')
            ->orderByDesc('id')
            ->first(['id', 'company', 'cutoff_date']);
    }

    /**
     * @param  list<PortfolioLoadRecord>  $snapshots
     * @param  Builder<ClientRecord>  $clients
     * @return array<int, SnapshotSums> By snapshot id.
     */
    private function sums(array $snapshots, Builder $clients): array
    {
        return $this->snapshotTotals->bySnapshot(
            array_map(fn (PortfolioLoadRecord $snapshot): int => $snapshot->id, $snapshots),
            $clients,
        );
    }

    /**
     * The totals of $snapshots together, at the oldest of their cutoff dates.
     *
     * @param  non-empty-list<PortfolioLoadRecord>  $snapshots
     * @param  array<int, SnapshotSums>  $sums  By snapshot id; a missing one adds nothing.
     */
    private function combine(array $snapshots, array $sums): PortfolioTotals
    {
        $combined = new SnapshotSums;
        $cutoffDate = $snapshots[0]->cutoff_date;

        foreach ($snapshots as $snapshot) {
            $combined = $combined->plus($sums[$snapshot->id] ?? new SnapshotSums);
            $cutoffDate = $snapshot->cutoff_date->lessThan($cutoffDate) ? $snapshot->cutoff_date : $cutoffDate;
        }

        return $combined->toTotals($cutoffDate);
    }

    /**
     * @param  Builder<ClientRecord>  $clients  Sorted to a unique order.
     * @return Generator<int, ClientSummary, mixed, void>
     */
    private function lazily(Builder $clients): Generator
    {
        for ($page = 1; ; $page++) {
            $records = (clone $clients)->forPage($page, self::CHUNK)->get();

            foreach ($this->mapper->summaries($records->all()) as $summary) {
                yield $summary;
            }

            if ($records->count() < self::CHUNK) {
                return;
            }
        }
    }
}
