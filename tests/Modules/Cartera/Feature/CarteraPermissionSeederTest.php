<?php

use App\Modules\Cartera\Database\Seeders\CarteraPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

dataset('cartera permissions', ['cartera.view', 'cartera.manage', 'cartera.export']);

it('gives every Cartera permission to the portfolio manager and analyst only', function (string $permission) {
    // Core runs first in DatabaseSeeder and creates the six roles.
    $this->seed(CorePermissionSeeder::class);
    $this->seed(CarteraPermissionSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role->value, 'web')->hasPermissionTo($permission))
            ->toBe(in_array($role, [Role::PortfolioManager, Role::PortfolioAnalyst], true), "{$role->value} / {$permission}");
    }
})->with('cartera permissions');

it('can be seeded twice without duplicating anything', function () {
    $this->seed(CarteraPermissionSeeder::class);
    $this->seed(CarteraPermissionSeeder::class);

    expect(Permission::query()->where('name', 'like', 'cartera.%')->count())->toBe(3);
});
