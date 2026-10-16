<?php

namespace App\Modules\Asesores\Application\Portfolio;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use Carbon\CarbonImmutable;

/**
 * Reads the dashboard through the portfolio contract, so every figure is
 * already limited to what the signed-in user may see.
 */
final readonly class PortfolioOverview
{
    /**
     * Clients listed in "Atención prioritaria".
     */
    public const int PRIORITY_CLIENTS = 5;

    /**
     * A client is a priority when an invoice is overdue by more than this.
     */
    public const int PRIORITY_DAYS = 90;

    public function __construct(private CarteraRepository $cartera) {}

    public function build(PortfolioFilter $filter, CarbonImmutable $today, int $historyDays): Overview
    {
        $scope = $filter->advisorQuery();
        $advisors = $this->cartera->advisors();

        return new Overview(
            totals: $this->cartera->totals($scope),
            priority: $this->priority($scope),
            history: $this->cartera->history($today->subDays($historyDays)->startOfDay(), $today, $scope),
            advisors: $advisors,
            selected: $this->selected($filter, $advisors),
        );
    }

    /**
     * @return list<ClientSummary>
     */
    private function priority(ClientQuery $scope): array
    {
        $query = new ClientQuery(
            advisorCode: $scope->advisorCode,
            companies: $scope->companies,
            sort: 'days_overdue',
            direction: 'desc',
        );
        $priority = [];

        // The clients come oldest overdue first, so the first one under the
        // threshold ends the search. allClients() is lazy: stopping early
        // reads one block, and it does not depend on the ?page= of the
        // request like the paginator does.
        foreach ($this->cartera->allClients($query) as $client) {
            if ($client->maxDaysOverdue <= self::PRIORITY_DAYS) {
                break;
            }

            $priority[] = $client;

            if (count($priority) === self::PRIORITY_CLIENTS) {
                break;
            }
        }

        return $priority;
    }

    /**
     * @param  list<Advisor>  $advisors
     */
    private function selected(PortfolioFilter $filter, array $advisors): ?Advisor
    {
        if ($filter->advisor === null) {
            return null;
        }

        foreach ($advisors as $advisor) {
            if ($filter->advisor->matches($advisor)) {
                return $advisor;
            }
        }

        return null;
    }
}
