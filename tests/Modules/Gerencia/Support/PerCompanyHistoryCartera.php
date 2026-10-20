<?php

namespace Tests\Modules\Gerencia\Support;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Wraps a CarteraRepository and gives each company its own history.
 *
 * FakeCarteraRepository::history() ignores its query, so a test that needs
 * different history per company registers it here: the companies of the
 * query pick the series, and no companies (the group view) picks the group
 * series. Everything else is delegated to the wrapped repository.
 */
final class PerCompanyHistoryCartera implements CarteraRepository
{
    /**
     * @var array<string, list<PortfolioTotals>>
     */
    private array $byCompany = [];

    /**
     * @var list<PortfolioTotals>
     */
    private array $group = [];

    /**
     * Queries received by history(), in order.
     *
     * @var list<ClientQuery|null>
     */
    public array $historyQueries = [];

    /**
     * Date ranges received by history() as [from, to] (Y-m-d), in order.
     *
     * @var list<array{string, string}>
     */
    public array $historyRanges = [];

    public function __construct(private readonly CarteraRepository $inner) {}

    /**
     * @param  list<PortfolioTotals>  $history
     */
    public function withCompanyHistory(CompanyCode $company, array $history): static
    {
        $this->byCompany[$company->value] = $history;

        return $this;
    }

    /**
     * @param  list<PortfolioTotals>  $history
     */
    public function withGroupHistory(array $history): static
    {
        $this->group = $history;

        return $this;
    }

    public function asSystem(): CarteraRepository
    {
        return $this->inner->asSystem();
    }

    public function paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator
    {
        return $this->inner->paginateClients($query, $perPage);
    }

    public function allClients(ClientQuery $query): iterable
    {
        return $this->inner->allClients($query);
    }

    public function client(int $clientId): ?ClientDetail
    {
        return $this->inner->client($clientId);
    }

    public function openInvoices(int $clientId): array
    {
        return $this->inner->openInvoices($clientId);
    }

    public function totals(?ClientQuery $query = null): PortfolioTotals
    {
        return $this->inner->totals($query);
    }

    public function history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array
    {
        // Keep the wrapped repository's range validation.
        $this->inner->history($from, $to, $query);
        $this->historyQueries[] = $query;
        $this->historyRanges[] = [$from->toDateString(), $to->toDateString()];

        $companies = $query?->companies ?? [];
        $series = $companies === []
            ? $this->group
            : ($this->byCompany[$companies[0]->value] ?? []);

        return array_values(array_filter(
            $series,
            fn (PortfolioTotals $totals): bool => $totals->cutoffDate !== null
                && $totals->cutoffDate->toDateString() >= $from->toDateString()
                && $totals->cutoffDate->toDateString() <= $to->toDateString(),
        ));
    }

    public function advisors(): array
    {
        return $this->inner->advisors();
    }
}
