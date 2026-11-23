<?php

namespace Tests\Modules\Soportes\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role for the Soportes tests. Seed
 * SoportesPermissionSeeder first so the roles carry the soportes permissions.
 */
final class SoportesUsers
{
    /**
     * An active user with $role, or without any role when null.
     */
    public static function create(?Role $role, string $name = 'María Paula Rojas'): User
    {
        $user = User::factory()->create(['name' => $name]);

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
        }

        return $user;
    }

    public static function advisor(string $name = 'Carlos Pérez'): User
    {
        return self::create(Role::SalesAdvisor, $name);
    }

    public static function analyst(): User
    {
        return self::create(Role::PortfolioAnalyst);
    }
}
