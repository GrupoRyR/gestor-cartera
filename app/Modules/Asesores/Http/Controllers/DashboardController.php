<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Asesores\Application\Portfolio\PortfolioOverview;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /asesores (asesores.inicio): totals, age chart, priority clients and
 * trend of the portfolio the user may see.
 */
final readonly class DashboardController
{
    public function __construct(private PortfolioOverview $overview) {}

    public function __invoke(Request $request): View
    {
        $filter = PortfolioFilter::fromQuery($request->query());
        $today = CarbonImmutable::today();

        return view('asesores::resumen', [
            'filter' => $filter,
            'overview' => $this->overview->build($filter, $today, (int) config('asesores.history.dashboard_days')),
            'today' => $today,
            'isManager' => $request->user()?->can('asesores.team') ?? false,
        ]);
    }
}
