<?php

namespace App\Modules\Cartera\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Cartera module. DatabaseSeeder runs it on every seed;
 * running it again changes nothing.
 */
final class CarteraPermissionSeeder extends PermissionSeeder
{
    /**
     * cartera.view: the dashboard, the Kanban, the client list and file, and
     * the commitments inbox. The portfolio manager and the analyst; advisors
     * and sales managers use the Asesores module instead.
     *
     * cartera.manage: write to a client's log (calls, notes, discounts
     * offered). Commitments follow Core's own rule instead (SDC analyst
     * only), which the CompromisoService enforces.
     *
     * cartera.export: download the client list and the client file as Excel.
     *
     * cartera.commitments.write: create, edit, fulfil and cancel the payment
     * commitments of SDC clients, which CompromisoService allows only to the
     * analyst (Part B, module 2, item 7). In SGX the advisor writes them, from
     * the Asesores module. The service stays the authority; this permission
     * only decides which buttons the screens show.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        $portfolioTeam = [Role::PortfolioManager, Role::PortfolioAnalyst];

        return [
            'cartera.view' => $portfolioTeam,
            'cartera.manage' => $portfolioTeam,
            'cartera.export' => $portfolioTeam,
            'cartera.commitments.write' => [Role::PortfolioAnalyst],
        ];
    }
}
