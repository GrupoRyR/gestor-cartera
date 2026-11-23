<?php

namespace App\Modules\Asesores\Application\Commitments;

use App\Modules\Core\Contracts\Data\Notice;
use Illuminate\Support\Facades\Auth;

/**
 * Where the balance notice of a partial payment leads in Asesores
 * (AsesoresServiceProvider registers it with AvisoService::linkUsing()): the
 * client file, for whoever opens Asesores, in the companies where advisors
 * write commitments (asesores.commitment_companies). Whoever writes them
 * (the advisor) lands with "Nuevo compromiso" open and the balance as its
 * value.
 *
 * Cartera registers its own resolver for the same kind and the first answer
 * wins. Each one passes for users without its module's view permission, so
 * an advisor gets this file and the portfolio team Cartera's; a user with
 * both permissions gets the one registered first.
 */
final class RemainingBalanceLink
{
    public function __invoke(Notice $notice): ?string
    {
        $user = Auth::user();

        if ($user === null
            || ! $user->can('asesores.view')
            || $notice->subjectType !== 'client'
            || $notice->subjectId === null
            || ! in_array($notice->company, (array) config('asesores.commitment_companies'), true)) {
            return null;
        }

        $query = [];

        if ($user->can('asesores.manage')) {
            $query['nuevo-compromiso'] = 1;
            $query['valor'] = SuggestedAmount::forLink($notice->data['remaining'] ?? null);
        }

        return route('asesores.clientes.show', ['client' => $notice->subjectId, ...array_filter($query, fn (mixed $value): bool => $value !== null)]);
    }
}
