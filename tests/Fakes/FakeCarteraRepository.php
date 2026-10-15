<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\AgingBuckets;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Tests\Fakes\Concerns\QueriesInMemory;

/**
 * In-memory CarteraRepository.
 *
 * Emulates the scope rule: with a CurrentCompany it limits results to its
 * scope (explicit ClientQuery::$companies to the available companies), and
 * restrictToAdvisor() emulates the asesor scope, so isolation tests can be
 * written before Core exists. Without them it behaves like an analyst who
 * sees everything. Totals and aging are computed from the open invoices of
 * the matching clients; history() returns the stored entries in the range
 * and ignores the query. Name order compares lowercase bytes, so accented
 * initials sort after "z".
 *
 * Copies made with asSystem() share the data of the original fake.
 */
final class FakeCarteraRepository implements CarteraRepository
{
    use QueriesInMemory;

    /**
     * Cutoff date reported by totals(); null until a test sets it.
     */
    public ?CarbonImmutable $cutoffDate = null;

    /**
     * @var array<int, ClientDetail>
     */
    private array $clients = [];

    /**
     * @var array<int, list<Invoice>>
     */
    private array $invoices = [];

    /**
     * @var list<Advisor>
     */
    private array $advisors = [];

    /**
     * @var list<PortfolioTotals>
     */
    private array $history = [];

    private ?int $advisorScope = null;

    /**
     * The fake whose data a system copy reads.
     */
    private ?self $origin = null;

    public function __construct(
        private readonly ?CurrentCompany $currentCompany = null,
    ) {}

    /**
     * Adds or replaces a client and its open invoices.
     *
     * @throws InvalidArgumentException When an invoice belongs to another client or company.
     */
    public function addClient(ClientDetail $client, Invoice ...$invoices): static
    {
        $summary = $client->summary;

        foreach ($invoices as $invoice) {
            if ($invoice->clientId !== $summary->id || $invoice->company !== $summary->company) {
                throw new InvalidArgumentException(
                    "Invoice {$invoice->documentNumber} belongs to client {$invoice->clientId} ({$invoice->company->value}), "
                    ."not to client {$summary->id} ({$summary->company->value}); build it with clientId: and company:.",
                );
            }
        }

        $data = $this->data();
        $data->clients[$summary->id] = $client;
        $data->invoices[$summary->id] = array_values($invoices);

        return $this;
    }

    public function addAdvisor(Advisor ...$advisors): static
    {
        $data = $this->data();
        $data->advisors = [...$data->advisors, ...array_values($advisors)];

        return $this;
    }

    public function addHistory(PortfolioTotals ...$totals): static
    {
        $data = $this->data();
        $data->history = [...$data->history, ...array_values($totals)];

        return $this;
    }

    /**
     * Emulates an asesor: only clients whose advisor has this code are
     * visible; null lifts the restriction.
     */
    public function restrictToAdvisor(?int $code): static
    {
        $this->advisorScope = $code;

        return $this;
    }

    public function asSystem(): CarteraRepository
    {
        $system = new self;
        $system->origin = $this->data();

        return $system;
    }

    public function paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator
    {
        return $this->paginateList($this->matching($query), $perPage);
    }

    public function allClients(ClientQuery $query): iterable
    {
        return $this->matching($query);
    }

    public function client(int $clientId): ?ClientDetail
    {
        $client = $this->data()->clients[$clientId] ?? null;

        return $client !== null && $this->visible($client->summary, $this->companiesInScope($this->currentCompany))
            ? $client
            : null;
    }

    public function openInvoices(int $clientId): array
    {
        if ($this->client($clientId) === null) {
            return [];
        }

        $invoices = $this->data()->invoices[$clientId] ?? [];

        usort($invoices, fn (Invoice $a, Invoice $b): int => [$a->dueAt, $a->id] <=> [$b->dueAt, $b->id]);

        return $invoices;
    }

    public function totals(?ClientQuery $query = null): PortfolioTotals
    {
        $clients = $this->matching($query ?? new ClientQuery);
        $cutoffDate = $this->data()->cutoffDate;

        if ($clients === [] && $cutoffDate === null) {
            return PortfolioTotals::empty();
        }

        $buckets = ['notDue' => 0.0, 'days1To30' => 0.0, 'days31To60' => 0.0, 'days61To90' => 0.0, 'over90' => 0.0];
        $overdueClients = 0;

        foreach ($clients as $client) {
            $hasOverdue = false;

            foreach ($this->data()->invoices[$client->id] ?? [] as $invoice) {
                $days = $invoice->daysOverdue;
                $bucket = match (true) {
                    $days <= 0 => 'notDue',
                    $days <= 30 => 'days1To30',
                    $days <= 60 => 'days31To60',
                    $days <= 90 => 'days61To90',
                    default => 'over90',
                };
                $buckets[$bucket] += $invoice->balance;
                $hasOverdue = $hasOverdue || $days > 0;
            }

            $overdueClients += $hasOverdue ? 1 : 0;
        }

        $aging = new AgingBuckets(...$buckets);

        return new PortfolioTotals(
            cutoffDate: $cutoffDate,
            total: $aging->total(),
            overdue: $aging->overdue(),
            clientCount: count($clients),
            overdueClientCount: $overdueClients,
            aging: $aging,
        );
    }

    public function history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array
    {
        if ($from->toDateString() > $to->toDateString()) {
            throw new InvalidArgumentException("The history range ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }

        $history = array_values(array_filter(
            $this->data()->history,
            fn (PortfolioTotals $totals): bool => $totals->cutoffDate !== null
                && $totals->cutoffDate->toDateString() >= $from->toDateString()
                && $totals->cutoffDate->toDateString() <= $to->toDateString(),
        ));

        usort($history, fn (PortfolioTotals $a, PortfolioTotals $b): int => $a->cutoffDate <=> $b->cutoffDate);

        return $history;
    }

    public function advisors(): array
    {
        $companies = $this->companiesInScope($this->currentCompany);

        $advisors = array_values(array_filter(
            $this->data()->advisors,
            fn (Advisor $advisor): bool => ($companies === null || in_array($advisor->company, $companies, true))
                && ($this->advisorScope === null || $advisor->code === $this->advisorScope),
        ));

        usort($advisors, fn (Advisor $a, Advisor $b): int => [mb_strtolower($a->name), $a->code] <=> [mb_strtolower($b->name), $b->code]);

        return $advisors;
    }

    /**
     * The fake that holds the data: the original one for system copies.
     */
    private function data(): self
    {
        return $this->origin ?? $this;
    }

    /**
     * @param  list<CompanyCode>|null  $companies  Null = any company.
     */
    private function visible(ClientSummary $client, ?array $companies): bool
    {
        return ($companies === null || in_array($client->company, $companies, true))
            && ($this->advisorScope === null || $client->advisor?->code === $this->advisorScope);
    }

    /**
     * Visible clients that match the query, in query order.
     *
     * @return list<ClientSummary>
     */
    private function matching(ClientQuery $query): array
    {
        $companies = $this->companiesInScope($this->currentCompany, $query->companies);
        $search = mb_strtolower(trim((string) $query->search));

        $clients = [];

        foreach ($this->data()->clients as $detail) {
            $client = $detail->summary;

            if (! $this->visible($client, $companies)
                || ($search !== '' && ! str_contains(mb_strtolower($client->name), $search) && ! str_contains(mb_strtolower($client->taxId), $search))
                || ($query->statuses !== [] && ! in_array($client->status, $query->statuses, true))
                || ($query->advisorCode !== null && $client->advisor?->code !== $query->advisorCode)
                || ($query->onlyOverdue && $client->overdue <= 0)
                || ($query->inactiveSince !== null && $client->lastActivityAt !== null && $client->lastActivityAt >= $query->inactiveSince)
            ) {
                continue;
            }

            $clients[] = $client;
        }

        $direction = $query->direction === 'desc' ? -1 : 1;

        usort($clients, function (ClientSummary $a, ClientSummary $b) use ($query, $direction): int {
            $order = ($this->sortKey($a, $query->sort) <=> $this->sortKey($b, $query->sort)) * $direction;

            // Ties: name, then id, both ascending.
            return $order !== 0 ? $order : [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id];
        });

        return $clients;
    }

    private function sortKey(ClientSummary $client, string $sort): string|int|float
    {
        return match ($sort) {
            'balance' => $client->balance,
            'overdue' => $client->overdue,
            'days_overdue' => $client->maxDaysOverdue,
            'status' => (int) array_search($client->status, ClientStatus::cases(), true),
            'last_activity' => $client->lastActivityAt?->getTimestamp() ?? PHP_INT_MIN,
            default => mb_strtolower($client->name),
        };
    }
}
