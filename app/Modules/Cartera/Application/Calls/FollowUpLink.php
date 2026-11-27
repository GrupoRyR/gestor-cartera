<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Modules\Core\Contracts\Data\Notice;
use Illuminate\Support\Facades\Auth;

/**
 * Where the bell notice of a call follow-up leads (CarteraServiceProvider
 * registers it with AvisoService::linkUsing()): the client file with the
 * "Registrar llamada" dialog open, for whoever may open Cartera.
 *
 * Like RemainingBalanceLink, the client file follows the active company, so
 * a notice of the other company opens on a 404 until the user switches.
 */
final class FollowUpLink
{
    public function __invoke(Notice $notice): ?string
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('cartera.view') || $notice->subjectType !== 'client' || $notice->subjectId === null) {
            return null;
        }

        return route('cartera.clientes.show', ['client' => $notice->subjectId, 'registrar-llamada' => 1]);
    }
}
