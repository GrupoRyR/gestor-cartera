<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * Read access to clients, invoices and receivables snapshots.
 *
 * Implemented by Core (phase 0b); tests use Tests\Fakes\FakeCarteraRepository.
 *
 * Scope rule: results are limited to the companies of CurrentCompany::scope()
 * (or to ClientQuery::$companies, when given, intersected with
 * CurrentCompany::available()) and to the clients the authenticated user may
 * see:
 * - asesor: their own clients (those whose SAP advisor is linked to them);
 * - gerente comercial: the clients of the advisors in their team;
 * - gerente de cartera, analista de cartera and admin: every client.
 * With no authenticated user, results are empty unless the repository was
 * obtained through asSystem(). A client outside the scope behaves as if it
 * did not exist.
 *
 * Unless stated otherwise, figures come from the latest receivables snapshot
 * of each company. Amounts are Colombian pesos.
 */
interface CarteraRepository
{
    /**
     * A copy without the user scope (every client of every company; an empty
     * ClientQuery::$companies means every company). Only for scheduled jobs,
     * queued work and event listeners; never in controllers.
     */
    public function asSystem(): CarteraRepository;

    /**
     * One page of clients; the page number comes from the request (?page=).
     *
     * @param  int  $perPage  At least 1.
     * @return LengthAwarePaginator<int, ClientSummary>
     *
     * @throws InvalidArgumentException When $perPage is below 1.
     */
    public function paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator;

    /**
     * Every matching client in query order, lazily, for exports.
     *
     * @return iterable<int, ClientSummary>
     */
    public function allClients(ClientQuery $query): iterable;

    /**
     * The client file; null when the client does not exist or is out of scope.
     */
    public function client(int $clientId): ?ClientDetail;

    /**
     * Open invoices of the client in its latest snapshot, oldest due date
     * first; empty when the client does not exist or is out of scope.
     *
     * @return list<Invoice>
     */
    public function openInvoices(int $clientId): array;

    /**
     * Totals and aging of the latest snapshot for the matching clients; null
     * means every client in scope. In the group view the latest snapshot of
     * each company is combined and PortfolioTotals::$cutoffDate is the oldest
     * of them. Returns PortfolioTotals::empty() when there is no snapshot.
     */
    public function totals(?ClientQuery $query = null): PortfolioTotals;

    /**
     * One PortfolioTotals per snapshot date between $from and $to
     * (inclusive), ascending, for trends. Sorting fields of $query are
     * ignored; null means every client in scope.
     *
     * @return list<PortfolioTotals>
     *
     * @throws InvalidArgumentException When $from is after $to.
     */
    public function history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array;

    /**
     * Advisors of the companies in scope that the user may see (an asesor
     * sees only themselves), ordered by name.
     *
     * @return list<Advisor>
     */
    public function advisors(): array;
}
