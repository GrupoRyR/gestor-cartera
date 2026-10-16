<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Http\Requests\MoveClientRequest;
use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * POST /cartera/clientes/{client}/estado (cartera.clientes.estado): changes
 * the status of a client by hand, from the Kanban board (JSON, called by the
 * page) or from the client file (a form that redirects back).
 *
 * Core's ClientStatusService decides who may do it and writes the log and the
 * audit trail; a client out of the user's reach is a 404 and a user without
 * the permission gets a 403.
 */
final class MoveClientController
{
    public function __invoke(MoveClientRequest $request, int $client, ClientStatusService $statuses): JsonResponse|RedirectResponse
    {
        $status = ClientStatus::from($request->validated('estado'));

        try {
            $summary = $statuses->change($client, $status, $request->validated('nota'));
        } catch (RecordNotFound) {
            abort(404);
        }

        $message = __('cartera::kanban.mensajes.movido', ['cliente' => $summary->name, 'estado' => $summary->status->label()]);

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $summary->id,
                'status' => $summary->status->value,
                'label' => $summary->status->label(),
                'balance' => $summary->balance,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }
}
