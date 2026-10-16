<?php

namespace App\Modules\Cartera\Application\Dashboard;

use App\Modules\Cartera\Domain\Dashboard\AgingSlices;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\GestionLogger;
use Carbon\CarbonImmutable;

/**
 * Reads the figures of the dashboard (Part B, module 2, items 1 and 2) from
 * the Core contracts; each one already applies the user's scope.
 */
final class BuildDashboard
{
    private const int TOP_DEBTORS = 10;

    /**
     * The week of the commitments card: today and the next six days.
     */
    private const int WEEK_DAYS = 7;

    /**
     * Commitments read for the week card. A week holds far fewer; the cap
     * only protects the page, and the count always covers all of them.
     */
    private const int WEEK_READ_LIMIT = 200;

    private const int RECENT_ACTIVITY = 8;

    /**
     * System entries are dropped after reading, so read more than shown.
     */
    private const int RECENT_ACTIVITY_READ = 40;

    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly CompromisoService $commitments,
        private readonly GestionLogger $log,
        private readonly CurrentCompany $company,
    ) {}

    /**
     * @param  int  $unmanagedDays  Days without a logged action after which an overdue client counts as unmanaged.
     */
    public function handle(int $unmanagedDays): Dashboard
    {
        $today = CarbonImmutable::today();
        $totals = $this->cartera->totals();
        $week = $this->commitments->paginate(new CommitmentQuery(
            statuses: [CommitmentStatus::Pending],
            dueFrom: $today,
            dueTo: $today->addDays(self::WEEK_DAYS - 1),
        ), self::WEEK_READ_LIMIT);

        /** @var list<Commitment> $weekCommitments */
        $weekCommitments = array_values($week->items());

        return new Dashboard(
            today: $today,
            scopeLabel: $this->company->active()->name ?? 'Grupo RYR',
            isGroup: $this->company->isGroup(),
            totals: $totals,
            aging: AgingSlices::from($totals->aging),
            comparison: $this->comparison(),
            topDebtors: $this->topDebtors(),
            weekCommitments: $weekCommitments,
            weekCommitmentsCount: $week->total(),
            weekCommitmentsAmount: array_sum(array_map(fn ($commitment): float => $commitment->amount, $weekCommitments)),
            unmanagedOverdue: $this->cartera->paginateClients(new ClientQuery(
                onlyOverdue: true,
                inactiveSince: $today->subDays($unmanagedDays),
            ), 1)->total(),
            unmanagedDays: $unmanagedDays,
            recentActivity: $this->recentActivity(),
        );
    }

    /**
     * One entry per company, only in the group view: that is where SGX and
     * SDC are set side by side.
     *
     * @return list<CompanyFigures>
     */
    private function comparison(): array
    {
        $companies = $this->company->scope();

        if (! $this->company->isGroup() || count($companies) < 2) {
            return [];
        }

        return array_map(
            fn ($company): CompanyFigures => new CompanyFigures(
                $company,
                $this->cartera->totals(new ClientQuery(companies: [$company->code])),
            ),
            $companies,
        );
    }

    /**
     * @return list<ClientSummary>
     */
    private function topDebtors(): array
    {
        $debtors = [];

        foreach ($this->cartera->allClients(new ClientQuery(onlyOverdue: true, sort: 'overdue', direction: 'desc')) as $client) {
            $debtors[] = $client;

            if (count($debtors) === self::TOP_DEBTORS) {
                break;
            }
        }

        return $debtors;
    }

    /**
     * @return list<Activity>
     */
    private function recentActivity(): array
    {
        $entries = array_filter(
            $this->log->recent(self::RECENT_ACTIVITY_READ),
            fn (Activity $activity): bool => $activity->type !== ActivityType::System,
        );

        return array_slice(array_values($entries), 0, self::RECENT_ACTIVITY);
    }
}
