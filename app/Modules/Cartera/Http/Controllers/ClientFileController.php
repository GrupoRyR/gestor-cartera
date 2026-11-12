<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\AssigneeOptions;
use App\Modules\Cartera\Application\Clients\LoadClientFile;
use App\Modules\Core\Contracts\ClientAssignmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /cartera/clientes/{client} (cartera.clientes.show): the client file.
 * A client that does not exist, or is out of the user's reach, is a 404.
 * Whoever may assign managers (core.clients.assign) gets the choices of the
 * "Gestor" form; $assigneeOptions is null for everyone else.
 */
final class ClientFileController
{
    public function __invoke(Request $request, int $client, LoadClientFile $files, ClientAssignmentService $assignments): View
    {
        $file = $files->handle($client) ?? abort(404);

        return view('cartera::clientes.show', [
            'file' => $file,
            'assigneeOptions' => $request->user()?->can('core.clients.assign')
                ? AssigneeOptions::from($assignments->assignees(), $file->detail->summary->manager)
                : null,
        ]);
    }
}
