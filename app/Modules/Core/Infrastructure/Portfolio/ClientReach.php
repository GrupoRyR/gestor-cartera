<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The clients the signed-in user may act on by id: those their roles reach
 * (PortfolioScope, docs/arquitectura.md §6.2) in every company they may work
 * in, not only the active one, so a write started before a company switch
 * still finds its client. Lists follow the active company instead
 * (CarteraRepository, GestionLogger::recent()). Guests reach nothing.
 */
final readonly class ClientReach
{
    public function __construct(
        private AuthFactory $auth,
        private CurrentCompany $currentCompany,
    ) {}

    /**
     * The scope of the signed-in user over every company available to them.
     */
    public function scope(): PortfolioScope
    {
        return PortfolioScope::forUser($this->auth->guard()->user(), $this->currentCompany, CompanyCode::cases());
    }

    /**
     * The client, when it exists, is within reach and, unless $withTrashed,
     * is not soft-deleted.
     */
    public function client(int $clientId, bool $withTrashed = false): ?ClientRecord
    {
        $clients = $withTrashed ? ClientRecord::withTrashed() : ClientRecord::query();

        return $this->scope()->clients($clients)->whereKey($clientId)->first();
    }
}
