<?php

namespace App\Modules\Core\Application\Commitments;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who writes (creates, edits, fulfils and cancels) the commitments of a
 * client, by company (docs/arquitectura.md §6.5; Part B, module 2, item 7):
 *
 * - SGX: the client's advisor, that is, a user with the asesor role linked
 *   to the SAP advisor (same company and code) the client has now;
 * - SDC: the portfolio analyst (analista_cartera).
 *
 * Nobody else writes, not even the portfolio manager or an admin: they only
 * read. The caller checks first that the client is within the user's
 * reach, so an advisor never learns that another advisor's client exists.
 */
final class CommitmentWriteRule
{
    /**
     * The user, when they may write the commitments of $client.
     *
     * @throws AuthorizationException
     */
    public function authorize(?Authenticatable $user, ClientRecord $client): User
    {
        if ($user instanceof User && $this->allows($user, $client)) {
            return $user;
        }

        throw new AuthorizationException(match ($client->company) {
            CompanyCode::SGX => 'In SGX only the advisor of the client writes its commitments.',
            CompanyCode::SDC => 'In SDC only the portfolio analyst writes commitments.',
        });
    }

    public function allows(User $user, ClientRecord $client): bool
    {
        $roles = $user->getRoleNames()->all();

        return match ($client->company) {
            CompanyCode::SGX => in_array(Role::SalesAdvisor->value, $roles, true)
                && $client->advisor_code !== null
                && AdvisorRecord::linkedTo($user)
                    ->where('company', CompanyCode::SGX)
                    ->where('code', $client->advisor_code)
                    ->exists(),
            CompanyCode::SDC => in_array(Role::PortfolioAnalyst->value, $roles, true),
        };
    }
}
