<?php

namespace App\Modules\Asesores\Http\Policies;

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * What a user may do with a client the portfolio already returned for them.
 *
 * Who can see a client at all is Core's scope (CarteraRepository returns null
 * for the rest, and controllers answer 404). This policy adds the layer above
 * it: the right permission, a company the user works in, and, for writing, the
 * client's own advisor.
 */
final readonly class ClientPolicy
{
    public function __construct(private CurrentCompany $current) {}

    /**
     * Open the client file, export it and send its WhatsApp message.
     */
    public function view(User $user, ClientDetail $client): bool
    {
        return $user->can('asesores.view') && $this->worksIn($client->summary->company);
    }

    /**
     * Write commitments and notes for the client: only its advisor, and only
     * in the companies where advisors write them. Core's CompromisoService
     * makes the final call.
     */
    public function manage(User $user, ClientDetail $client): bool
    {
        return $this->view($user, $client)
            && $user->can('asesores.manage')
            && in_array($client->summary->company->value, (array) config('asesores.commitment_companies'), true)
            && $client->summary->advisor?->userId === $user->getKey();
    }

    /**
     * Add notes to the log of the client: its own advisor, in any company
     * (commitments are the ones limited to SGX). Core's GestionLogger makes
     * the final call.
     */
    public function annotate(User $user, ClientDetail $client): bool
    {
        return $this->view($user, $client)
            && $user->can('asesores.manage')
            && $client->summary->advisor?->userId === $user->getKey();
    }

    private function worksIn(CompanyCode $company): bool
    {
        foreach ($this->current->available() as $available) {
            if ($available->code === $company) {
                return true;
            }
        }

        return false;
    }
}
