<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Activity\ClientNotes;
use App\Modules\Asesores\Http\Requests\StoreNoteRequest;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Notes in the log of a client. The client is found through the portfolio
 * first, so one outside the user's scope is a 404; then the policy asks for
 * its own advisor.
 */
final readonly class NoteController
{
    public function __construct(
        private CarteraRepository $cartera,
        private ClientNotes $notes,
    ) {}

    /**
     * POST /asesores/clientes/{client}/notas.
     */
    public function store(StoreNoteRequest $request, int $client): RedirectResponse
    {
        $detail = $this->cartera->client($client) ?? abort(404);

        Gate::authorize('annotate', $detail);

        try {
            $this->notes->add($client, $request->text());
        } catch (RecordNotFound) {
            abort(404);
        }

        return redirect()
            ->route('asesores.clientes.show', $client)
            ->with('status', __('asesores::ficha.bitacora.nota.guardada'));
    }
}
