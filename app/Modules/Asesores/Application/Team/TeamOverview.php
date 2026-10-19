<?php

namespace App\Modules\Asesores\Application\Team;

use App\Modules\Asesores\Domain\Chart\BarWidth;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;

/**
 * The advisors a sales manager looks after, each with their portfolio.
 *
 * Who those advisors are is Core's answer (CarteraRepository::advisors():
 * the team of the manager and the advisors linked to them), and every figure
 * is read with the advisor and company in the query, so the scope of the
 * signed-in user applies to each of them.
 */
final readonly class TeamOverview
{
    public function __construct(
        private CarteraRepository $cartera,
        private CompromisoService $commitments,
    ) {}

    public function build(): TeamPage
    {
        $rows = [];

        foreach ($this->cartera->advisors() as $advisor) {
            $rows[] = [
                'advisor' => $advisor,
                'totals' => $this->cartera->totals(new ClientQuery(advisorCode: $advisor->code, companies: [$advisor->company])),
                'broken' => $this->commitments->paginate(
                    new CommitmentQuery(advisorCode: $advisor->code, statuses: [CommitmentStatus::Broken], companies: [$advisor->company]),
                    1,
                )->total(),
            ];
        }

        // The most overdue first; the bigger portfolio first among equals.
        usort($rows, fn (array $a, array $b): int => $b['totals']->overdue <=> $a['totals']->overdue
            ?: $b['totals']->total <=> $a['totals']->total
            ?: $a['advisor']->name <=> $b['advisor']->name);

        $biggest = $rows === [] ? 0.0 : max(array_map(fn (array $row): float => abs($row['totals']->overdue), $rows));
        $team = array_map(
            fn (array $row): TeamRow => new TeamRow($row['advisor'], $row['totals'], $row['broken'], BarWidth::of($row['totals']->overdue, $biggest)),
            $rows,
        );

        $cutoff = null;
        $cents = ['total' => 0, 'overdue' => 0];
        $clients = 0;

        foreach ($team as $row) {
            $clients += $row->totals->clientCount;
            $cents['total'] += (int) round($row->totals->total * 100);
            $cents['overdue'] += (int) round($row->totals->overdue * 100);

            if ($row->totals->cutoffDate !== null && ($cutoff === null || $row->totals->cutoffDate < $cutoff)) {
                $cutoff = $row->totals->cutoffDate;
            }
        }

        return new TeamPage($team, $clients, $cents['total'] / 100, $cents['overdue'] / 100, $cutoff);
    }
}
