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
     * cartera.commitments.status: mark any commitment fulfilled or broken, in
     * SGX and SDC, including those the advisor created. The portfolio manager
     * and the analyst; Core's CommitmentWriteRule::authorizeStatus() is the
     * authority. They never change the date, amount or notes in SGX.
     *
     * cartera.clients.start_on_mine: the client list opens on «Mi cartera»
     * (the clients whose manager is the user) instead of the whole
     * portfolio. The analyst only. It is a default, never a restriction: the
     * user can switch to «Toda la cartera».
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
            'cartera.commitments.status' => $portfolioTeam,
            'cartera.clients.start_on_mine' => [Role::PortfolioAnalyst],
        ];
    }

    /**
     * cartera.clients.start_on_mine is a default, not a capability: the
     * superadmin opens the client list on the whole portfolio.
     * cartera.commitments.write and cartera.commitments.status stay with
     * whoever collects (separation of duties); Core's CommitmentWriteRule
     * would refuse the superadmin anyway, so the screens do not show it
     * buttons it cannot use.
     *
     * @return list<string>
     */
    protected function superAdminExcludes(): array
    {
        return ['cartera.clients.start_on_mine', 'cartera.commitments.write', 'cartera.commitments.status'];
    }
}
