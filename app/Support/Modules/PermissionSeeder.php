<?php

namespace App\Support\Modules;

use App\Modules\Core\Contracts\Enums\Role as UserRole;
use BackedEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Base of each module's <Module>PermissionSeeder, which DatabaseSeeder
 * discovers (docs/prompt-gestor-cartera.md, Part C.2). Running it again is
 * safe: permissions and roles are created only when missing.
 *
 * Every permission also goes to the superadmin role, so a module's new
 * permissions reach it without a change here, except the ones the module
 * lists in superAdminExcludes().
 */
abstract class PermissionSeeder extends Seeder
{
    /**
     * Permissions of the module and the roles that receive each one.
     * Names follow "<module-key>.<action>", e.g. "cartera.view".
     *
     * @return array<string, list<string|BackedEnum>>
     */
    abstract public function permissions(): array;

    /**
     * Permissions of the module the superadmin does not receive: the ones
     * that change a default behaviour instead of granting a capability
     * (for example, which tab a list opens on).
     *
     * @return list<string>
     */
    protected function superAdminExcludes(): array
    {
        return [];
    }

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $excluded = $this->superAdminExcludes();

        foreach ($this->permissions() as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');

            // DatabaseSeeder mutes model events, which skips spatie's own
            // cache reset; without it the next lookup misses new permissions.
            $registrar->forgetCachedPermissions();

            $roleNames = array_map(
                fn (string|BackedEnum $role): string => $role instanceof BackedEnum ? (string) $role->value : $role,
                $roles,
            );

            if (! in_array($name, $excluded, true)) {
                $roleNames[] = UserRole::SuperAdmin->value;
            }

            foreach (array_unique($roleNames) as $roleName) {
                Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
