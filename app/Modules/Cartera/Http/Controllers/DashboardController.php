<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Dashboard\BuildDashboard;
use Illuminate\Contracts\View\View;

/**
 * GET /cartera (cartera.tablero): the dashboard of the portfolio manager and
 * the analyst.
 */
final class DashboardController
{
    public function __invoke(BuildDashboard $dashboard): View
    {
        return view('cartera::tablero.index', [
            'dashboard' => $dashboard->handle((int) config('cartera.sin_gestion_dias')),
        ]);
    }
}
