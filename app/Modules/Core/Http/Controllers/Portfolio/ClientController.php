<?php

namespace App\Modules\Core\Http\Controllers\Portfolio;

use App\Modules\Core\Application\Portfolio\ClientDeletion;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Http\RedirectResponse;

/**
 * Soft delete and restore of clients (core.clientes.*), for portfolio
 * managers (core.clients.delete), called from the pages of other modules
 * (Cartera), so every action goes back to where it came from with the
 * "status" flash. A client out of the user's reach is a 404.
 */
final class ClientController
{
    public function __construct(private readonly ClientDeletion $deletion) {}

    /**
     * DELETE /clientes/{client}. {client} finds only clients not deleted.
     */
    public function destroy(ClientRecord $client): RedirectResponse
    {
        try {
            $this->deletion->delete($client);
        } catch (RecordNotFound) {
            abort(404);
        }

        return back()->with('status', __('core::clientes.mensajes.eliminado', ['nombre' => $client->name]));
    }

    /**
     * POST /clientes/{client}/restaurar. The route also finds deleted clients.
     */
    public function restore(ClientRecord $client): RedirectResponse
    {
        try {
            $restored = $this->deletion->restore($client);
        } catch (RecordNotFound) {
            abort(404);
        }

        $message = $restored ? 'core::clientes.mensajes.restaurado' : 'core::clientes.mensajes.no_eliminado';

        return back()->with('status', __($message, ['nombre' => $client->name]));
    }
}
