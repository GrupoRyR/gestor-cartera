<?php

namespace App\Modules\Cartera\Application\Commitments;

use App\Modules\Cartera\Domain\Commitments\CommitmentWriting;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\Auth;

/**
 * Where the balance notice of a partial payment leads in Cartera
 * (CarteraServiceProvider registers it with AvisoService::linkUsing()):
 * the client file, for whoever may open Cartera. Whoever writes the
 * commitments of the client's company (the analyst, in SDC) lands with the
 * "Nuevo compromiso" form open and the balance as its value.
 *
 * The client file follows the active company (CurrentCompany::scope()), so
 * a notice of the other company opens on a 404 until the user switches to
 * it or to the group view; Core's switch is a POST that goes back, so a
 * link cannot do it.
 */
final class RemainingBalanceLink
{
    public function __invoke(Notice $notice): ?string
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('cartera.view') || $notice->subjectType !== 'client' || $notice->subjectId === null) {
            return null;
        }

        $company = CompanyCode::tryFrom((string) $notice->company);
        $query = [];

        if ($company !== null && CommitmentWriting::byCollector($company) && $user->can('cartera.commitments.write')) {
            $query['nuevo-compromiso'] = 1;
            $query['valor'] = SuggestedAmount::from($notice->data['remaining'] ?? null);
        }

        return route('cartera.clientes.show', ['client' => $notice->subjectId, ...array_filter($query, fn (mixed $value): bool => $value !== null)]);
    }
}
