<?php

namespace App\Modules\Asesores\Application\Agenda;

use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Asesores\Application\Support\FirstPage;
use App\Modules\Asesores\Domain\Agenda\AgendaPlanner;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use Carbon\CarbonImmutable;

/**
 * Reads the open commitments the user may see (CompromisoService applies the
 * scope: an advisor's own clients, a sales manager's team) and plans them.
 */
final readonly class AgendaReader
{
    /**
     * Open commitments read at most; the agenda says so when there are more.
     */
    public const int LIMIT = 200;

    public function __construct(
        private CompromisoService $commitments,
        private CarteraRepository $cartera,
    ) {}

    public function read(PortfolioFilter $filter, CarbonImmutable $today): AgendaPage
    {
        $page = FirstPage::read(fn () => $this->commitments->paginate(
            new CommitmentQuery(
                advisorCode: $filter->advisor?->code,
                statuses: [CommitmentStatus::Pending, CommitmentStatus::Broken],
                companies: $filter->advisor === null ? [] : [$filter->advisor->company],
                sort: 'due_date',
                direction: 'asc',
            ),
            self::LIMIT,
        ));

        /** @var list<Commitment> $commitments */
        $commitments = array_values($page->items());

        return new AgendaPage(
            agenda: AgendaPlanner::plan($commitments, $today),
            advisors: $this->cartera->advisors(),
            truncated: $page->total() > count($commitments),
        );
    }
}
