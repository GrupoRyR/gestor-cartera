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

it('lets advisors and sales managers open the portfolio viewer', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.view'))
            ->toBe(in_array($role, [Role::SalesAdvisor, Role::SalesManager], true), $role->value);
    }
});

it('lets only advisors write commitments and notes', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.manage'))->toBe($role === Role::SalesAdvisor, $role->value);
    }
});

it('lets only sales managers see their team', function () {
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(asesoresRoleCan($role, 'asesores.team'))->toBe($role === Role::SalesManager, $role->value);
    }
});

it('can run again without duplicating permissions', function () {
    $this->seed(AsesoresPermissionSeeder::class);
    $this->seed(AsesoresPermissionSeeder::class);

    foreach (['asesores.view', 'asesores.manage', 'asesores.team'] as $name) {
        expect(Permission::query()->where('name', $name)->count())->toBe(1, $name);
    }
});
