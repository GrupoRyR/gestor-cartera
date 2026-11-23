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
 * Who writes the commitments of a client, by company (docs/arquitectura.md
 * §6.5; Part B, module 2, item 7).
 *
 * Writing (create, edit, cancel; authorize()):
 *
 * - SGX: the client's advisor, that is, a user with the asesor role linked
 *   to the SAP advisor (same company and code) the client has now;
 * - SDC: the portfolio analyst (analista_cartera).
 *
 * Changing the status (fulfil, partially pay, break; authorizeStatus()):
 * the writer, and in both companies the portfolio analyst and manager, who
 * follow up on every commitment.
 *
 * Nobody else writes, not even an admin, a sales manager or the superadmin
 * (separation of duties). The caller checks first that the client is
 * within the user's reach, so an advisor never learns that another
 * advisor's client exists.
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

    /**
     * The user, when they may mark the commitments of $client fulfilled or
     * broken.
     *
     * @throws AuthorizationException
     */
    public function authorizeStatus(?Authenticatable $user, ClientRecord $client): User
    {
        if ($user instanceof User && $this->allowsStatus($user, $client)) {
            return $user;
        }

        throw new AuthorizationException(match ($client->company) {
            CompanyCode::SGX => 'In SGX only the advisor of the client and the portfolio team change the status of its commitments.',
            CompanyCode::SDC => 'In SDC only the portfolio team changes the status of commitments.',
        });
    }

    public function allowsStatus(User $user, ClientRecord $client): bool
    {
        return $user->hasAnyRole([Role::PortfolioAnalyst->value, Role::PortfolioManager->value])
            || $this->allows($user, $client);
    }

    public function allows(User $user, ClientRecord $client): bool
    {
        $roles = $user->getRoleNames()->all();

        if (! self::advisorWrites($client->company)) {
            return in_array(Role::PortfolioAnalyst->value, $roles, true);
        }

        return in_array(Role::SalesAdvisor->value, $roles, true)
            && $client->advisor_code !== null
            && AdvisorRecord::linkedTo($user)
                ->where('company', $client->company)
                ->where('code', $client->advisor_code)
                ->exists();
    }

    /**
     * Whether the client's advisor writes the commitments of $company (SGX);
     * otherwise the portfolio analyst does (SDC).
     */
    public static function advisorWrites(CompanyCode $company): bool
    {
        return $company === CompanyCode::SGX;
    }
}
