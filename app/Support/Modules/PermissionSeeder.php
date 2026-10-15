<?php

namespace App\Support\Modules;

use BackedEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Base of each module's <Module>PermissionSeeder, which DatabaseSeeder
 * discovers (docs/prompt-gestor-cartera.md, Part C.2). Running it again is
 * safe: permissions and roles are created only when missing.
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

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach ($this->permissions() as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');

            // DatabaseSeeder mutes model events, which skips spatie's own
            // cache reset; without it the next lookup misses new permissions.
            $registrar->forgetCachedPermissions();

            foreach ($roles as $role) {
                $roleName = $role instanceof BackedEnum ? (string) $role->value : $role;

                Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
