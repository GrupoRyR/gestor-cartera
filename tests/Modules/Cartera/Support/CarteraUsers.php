<?php

namespace Tests\Modules\Cartera\Support;

use App\Models\User;
use App\Modules\Cartera\Database\Seeders\CarteraPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role, after seeding the permissions of Core and
 * Cartera (both seeders are safe to run again).
 */
final class CarteraUsers
{
    /**
     * An active user with $role, or without any role when null.
     */
    public static function create(?Role $role = Role::PortfolioAnalyst): User
    {
        (new CorePermissionSeeder)->run();
        (new CarteraPermissionSeeder)->run();

        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
        }

        return $user;
    }

    public static function analyst(): User
    {
        return self::create(Role::PortfolioAnalyst);
    }

    public static function manager(): User
    {
        return self::create(Role::PortfolioManager);
    }
}
