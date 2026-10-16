<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Core module. DatabaseSeeder runs it on every seed;
 * running it again changes nothing.
 */
final class CorePermissionSeeder extends PermissionSeeder
{
    /**
     * core.access: may use the platform. Every role receives it; a user
     * without a role lacks it and sees the screen that asks them to request
     * access (Part A.5). Seeding it also guarantees that the six roles of
     * Role exist, so the Equipo screen can assign them.
     *
     * core.team.manage: the Equipo screen (users, roles, companies,
     * activation and soft delete). Only admins (IT, Part A.5).
     *
     * core.companies.manage: the Parámetros screen (grace days and due-soon
     * window of each company). Admins (IT: "usuarios, parámetros y buzones",
     * Part A.5) and the portfolio manager, who tunes the status rules of the
     * portfolio.
     *
     * core.activities.delete: soft-delete an entry of a client's log
     * (gestión). The portfolio manager and the analyst, who run the log.
     *
     * core.clients.change_status: change a client's status by hand (Kanban
     * and client page, ClientStatusService). The portfolio manager and the
     * analyst.
     *
     * core.clients.delete: soft-delete and restore clients. Only the
     * portfolio manager, a decision still to confirm with the business.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'core.access' => Role::cases(),
            'core.team.manage' => [Role::Admin],
            'core.companies.manage' => [Role::Admin, Role::PortfolioManager],
            'core.activities.delete' => [Role::PortfolioManager, Role::PortfolioAnalyst],
            'core.clients.change_status' => [Role::PortfolioManager, Role::PortfolioAnalyst],
            'core.clients.delete' => [Role::PortfolioManager],
        ];
    }
}
