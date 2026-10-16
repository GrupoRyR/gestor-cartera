<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Kanban\BuildKanbanBoard;
use App\Modules\Core\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /cartera/kanban (cartera.kanban): the clients laid out by status.
 */
final class KanbanController
{
    public function __invoke(Request $request, BuildKanbanBoard $board, CurrentCompany $company): View
    {
        return view('cartera::kanban.index', [
            'board' => $board->handle((int) config('cartera.kanban_max_por_columna')),
            'isGroup' => $company->isGroup(),
            // The status service is the authority; this only hides a control that would be refused.
            'canMove' => $request->user()?->can('core.clients.change_status') ?? false,
        ]);
    }
}
