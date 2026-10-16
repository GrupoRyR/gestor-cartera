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
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        $portfolioTeam = [Role::PortfolioManager, Role::PortfolioAnalyst];

        return [
            'cartera.view' => $portfolioTeam,
            'cartera.manage' => $portfolioTeam,
            'cartera.export' => $portfolioTeam,
        ];
    }
}
