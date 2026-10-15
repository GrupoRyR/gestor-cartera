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
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'core.access' => Role::cases(),
            'core.team.manage' => [Role::Admin],
        ];
    }
}
