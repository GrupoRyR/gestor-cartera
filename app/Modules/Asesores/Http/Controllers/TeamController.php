<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Team\TeamOverview;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * GET /asesores/equipo (asesores.equipo.index): the advisors of a sales
 * manager with the figures of each portfolio.
 */
final readonly class TeamController
{
    public function __construct(private TeamOverview $team) {}

    public function __invoke(): View
    {
        return view('asesores::equipo', [
            'team' => $this->team->build(),
            'today' => CarbonImmutable::today(),
        ]);
    }
}
