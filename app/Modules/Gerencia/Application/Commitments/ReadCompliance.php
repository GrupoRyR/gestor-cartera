<?php

namespace App\Modules\Gerencia\Application\Commitments;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Gerencia\Domain\Commitments\AdvisorCompliance;
use App\Modules\Gerencia\Domain\Commitments\ComplianceTally;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;

/**
 * Reads the commitments due in a period, page by page up to a cap, and
 * measures how well each advisor kept them.
 */
final class ReadCompliance
{
    public function __construct(
        private readonly CompromisoService $commitments,
        private readonly CarteraRepository $cartera,
    ) {}

    public function handle(ReportPeriod $period, CarbonImmutable $today): ComplianceReport
    {
        $query = new CommitmentQuery(dueFrom: $period->from, dueTo: $period->to, sort: 'due_date');
        $perPage = max(1, (int) config('gerencia.compliance_page_size', 200));
        $maxPages = max(1, (int) config('gerencia.compliance_max_pages', 50));

        $all = [];
        $truncated = false;

        // The contract reads the page from the current-page resolver (?page=),
        // so each page is requested through it and the resolver is put back.
        try {
            for ($page = 1; ; $page++) {
                Paginator::currentPageResolver(fn (): int => $page);

                $result = $this->commitments->paginate($query, $perPage);

                /** @var list<Commitment> $items */
                $items = array_values($result->items());
                $all = [...$all, ...$items];

                if ($page >= $result->lastPage()) {
                    break;
                }

                if ($page >= $maxPages) {
                    $truncated = true;
                    break;
                }
            }
        } finally {
            Paginator::currentPageResolver(self::requestPage(...));
        }

        $names = $this->advisorNames();

        return new ComplianceReport(
            rows: array_map(
                fn (AdvisorCompliance $compliance): ComplianceRow => new ComplianceRow(
                    $compliance,
                    $compliance->advisorCode === null ? null : ($names[$compliance->company->value.'|'.$compliance->advisorCode] ?? null),
                ),
                ComplianceTally::fromCommitments($all, $today),
            ),
            truncated: $truncated,
        );
    }

    /**
     * @return array<string, string> Advisor name by "company|code".
     */
    private function advisorNames(): array
    {
        $names = [];

        foreach ($this->cartera->advisors() as $advisor) {
            $names[$advisor->company->value.'|'.$advisor->code] = $advisor->name;
        }

        return $names;
    }

    /**
     * The framework's own page resolver: the page parameter of the request.
     */
    private static function requestPage(string $pageName = 'page'): int
    {
        $page = request()->input($pageName);

        return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
    }
}
