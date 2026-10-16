<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Portfolio\HistoryDays;
use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /asesores/historico (asesores.historico): the portfolio at each cutoff
 * of the chosen period.
 */
final readonly class HistoryController
{
    public function __construct(private CarteraRepository $cartera) {}

    public function __invoke(Request $request): View
    {
        $filter = PortfolioFilter::fromQuery($request->query());
        $days = HistoryDays::fromQuery(
            $request->query(),
            array_map('intval', (array) config('asesores.history.ranges')),
            (int) config('asesores.history.page_days'),
        );
        $today = CarbonImmutable::today();

        return view('asesores::historico', [
            'filter' => $filter,
            'days' => $days,
            'today' => $today,
            'history' => $this->cartera->history($today->subDays($days)->startOfDay(), $today, $filter->advisorQuery()),
            'advisors' => $this->cartera->advisors(),
        ]);
    }
}
