<?php

use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

it('creates the six roles, each allowed to use the platform', function () {
    $this->seed(CorePermissionSeeder::class);

    expect(SpatieRole::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing(array_map(fn (Role $role): string => $role->value, Role::cases()));

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role, 'web')->hasPermissionTo('core.access'))->toBeTrue();
    }
});

it('lets only admins manage the team', function () {
    $this->seed(CorePermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role, 'web')->hasPermissionTo('core.team.manage'))->toBe($role === Role::Admin);
    }
});

it('lets portfolio managers and analysts remove entries of a client log', function () {
    $this->seed(CorePermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role, 'web')->hasPermissionTo('core.activities.delete'))
            ->toBe(in_array($role, [Role::PortfolioManager, Role::PortfolioAnalyst], true));
    }
});

it('lets portfolio managers and analysts change client statuses by hand', function () {
    $this->seed(CorePermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role, 'web')->hasPermissionTo('core.clients.change_status'))
            ->toBe(in_array($role, [Role::PortfolioManager, Role::PortfolioAnalyst], true));
    }
});

it('lets only portfolio managers remove and restore clients', function () {
    $this->seed(CorePermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role, 'web')->hasPermissionTo('core.clients.delete'))->toBe($role === Role::PortfolioManager);
    }
});

it('can run again without duplicating permissions or roles', function () {
    $this->seed(CorePermissionSeeder::class);
    $this->seed(CorePermissionSeeder::class);

    expect(Permission::query()->where('name', 'core.access')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'core.team.manage')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'core.activities.delete')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'core.clients.change_status')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'core.clients.delete')->count())->toBe(1)
        ->and(SpatieRole::query()->count())->toBe(count(Role::cases()));
});
