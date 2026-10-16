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

it('can run again without duplicating permissions or roles', function () {
    $this->seed(CorePermissionSeeder::class);
    $this->seed(CorePermissionSeeder::class);

    expect(Permission::query()->where('name', 'core.access')->count())->toBe(1)
        ->and(SpatieRole::query()->count())->toBe(count(Role::cases()));
});
