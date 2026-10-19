<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Agenda\AgendaReader;
use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /asesores/agenda (asesores.agenda): the open payment commitments of the
 * portfolio the user may see, grouped by when they matter.
 */
final readonly class AgendaController
{
    public function __construct(private AgendaReader $reader) {}

    public function __invoke(Request $request): View
    {
        $filter = PortfolioFilter::fromQuery($request->query());
        $today = CarbonImmutable::today();

        return view('asesores::agenda', [
            'filter' => $filter,
            'page' => $this->reader->read($filter, $today),
            'today' => $today,
        ]);
    }
}
