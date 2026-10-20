<?php

namespace App\Modules\Gerencia\Http\Controllers;

use App\Modules\Gerencia\Application\Board\BuildManagementBoard;
use App\Modules\Gerencia\Http\Requests\BoardRequest;
use Illuminate\Contracts\View\View;

/**
 * GET /gerencia (gerencia.tablero): the management dashboard of the
 * portfolio manager.
 */
final class BoardController
{
    public function __invoke(BoardRequest $request, BuildManagementBoard $board): View
    {
        return view('gerencia::tablero.index', [
            'board' => $board->handle($request->period()),
        ]);
    }
}
