<?php

namespace App\Modules\Asesores\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Asesores module. DatabaseSeeder runs it on every seed;
 * running it again changes nothing.
 */
final class AsesoresPermissionSeeder extends PermissionSeeder
{
    /**
     * asesores.view: open the portfolio viewer (dashboard, clients, client
     * file, agenda, history, exports). What each person sees is decided by
     * Core's portfolio scope, not by this permission.
     *
     * asesores.manage: write commitments and notes. Advisors only: in SGX the
     * client's advisor writes them; sales managers read.
     *
     * asesores.team: the team screen of a sales manager.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'asesores.view' => [Role::SalesAdvisor, Role::SalesManager],
            'asesores.manage' => [Role::SalesAdvisor],
            'asesores.team' => [Role::SalesManager],
        ];
    }
}
