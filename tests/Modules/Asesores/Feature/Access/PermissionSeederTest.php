<?php

use App\Modules\Asesores\Database\Seeders\AsesoresPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Whether $role holds $permission after the Asesores seeder ran.
 */
function asesoresRoleCan(Role $role, string $permission): bool
{
    return SpatieRole::findOrCreate($role->value, 'web')->hasPermissionTo($permission);
}

it('lets advisors, sales managers and the superadmin open the portfolio viewer', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.view'))
            ->toBe(in_array($role, [Role::SalesAdvisor, Role::SalesManager, Role::SuperAdmin], true), $role->value);
    }
});

it('lets only advisors and the superadmin hold the write permission', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.manage'))->toBe(in_array($role, [Role::SalesAdvisor, Role::SuperAdmin], true), $role->value);
    }
});

it('lets only sales managers and the superadmin see the team screen', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.team'))->toBe(in_array($role, [Role::SalesManager, Role::SuperAdmin], true), $role->value);
    }
});

it('can run again without duplicating permissions', function () {
    $this->seed(AsesoresPermissionSeeder::class);
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (['asesores.view', 'asesores.manage', 'asesores.team'] as $name) {
        expect(Permission::query()->where('name', $name)->count())->toBe(1, $name);
    }
});
