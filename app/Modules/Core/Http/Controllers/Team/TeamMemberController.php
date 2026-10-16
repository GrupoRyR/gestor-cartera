<?php

namespace App\Modules\Core\Http\Controllers\Team;

use App\Modules\Core\Application\Team\TeamDirectory;
use App\Modules\Core\Application\Team\TeamFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Equipo screen (core.equipo.*), for admins only (core.team.manage).
 */
final class TeamMemberController
{
    /**
     * GET /equipo: one tab per state, searched, sorted and paginated.
     */
    public function index(Request $request, TeamDirectory $directory): View
    {
        $filter = TeamFilter::fromQuery($request->query());
        $members = $directory->page($filter);

        return view('core::equipo.index', [
            'filter' => $filter,
            'members' => $members,
            'companies' => $directory->companiesOf($members->items()),
            'counts' => $directory->counts($filter->search),
        ]);
    }
}
