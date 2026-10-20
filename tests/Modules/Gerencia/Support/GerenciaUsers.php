<?php

namespace Tests\Modules\Gerencia\Support;

use App\Models\User;
use App\Modules\Cartera\Database\Seeders\CarteraPermissionSeeder;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Gerencia\Database\Seeders\GerenciaPermissionSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role, after seeding the permissions of Core and
 * Gerencia, plus the Cartera and Cobro ones the manager's menu and the
 * links of the dashboard depend on (every seeder is safe to run again).
 */
final class GerenciaUsers
{
    /**
     * An active user with $role, or without any role when null.
     */
    public static function create(?Role $role = Role::PortfolioManager): User
    {
        (new CorePermissionSeeder)->run();
        (new GerenciaPermissionSeeder)->run();
        (new CarteraPermissionSeeder)->run();
        (new CobroPermissionSeeder)->run();

        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
        }

        return $user;
    }

    /**
     * Miguel: the only role that opens the dashboard.
     */
    public static function manager(): User
    {
        return self::create(Role::PortfolioManager);
    }
}
