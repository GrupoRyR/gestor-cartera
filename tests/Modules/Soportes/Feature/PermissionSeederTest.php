<?php

use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
});

/**
 * The soportes.* permissions of a role, sorted.
 *
 * @return list<string>
 */
function soportesPermissionsOf(Role $role): array
{
    $names = SpatieRole::findOrCreate($role->value, 'web')->permissions->pluck('name')
        ->filter(fn (string $name): bool => str_starts_with($name, 'soportes.'))
        ->sort()
        ->values()
        ->all();

    return $names;
}

it('gives each role exactly its soportes permissions', function (Role $role, array $expected) {
    expect(soportesPermissionsOf($role))->toBe($expected);
})->with([
    'asesor' => [Role::SalesAdvisor, ['soportes.upload', 'soportes.view']],
    'analista de cartera' => [Role::PortfolioAnalyst, ['soportes.review', 'soportes.upload', 'soportes.view']],
    'gerente de cartera' => [Role::PortfolioManager, ['soportes.review', 'soportes.upload', 'soportes.view']],
    'superadministrador' => [Role::SuperAdmin, ['soportes.view']],
    'tesorería' => [Role::Treasury, []],
    'administrador' => [Role::Admin, []],
    'gerente comercial' => [Role::SalesManager, []],
]);

it('can run again without changing anything', function () {
    $this->seed(SoportesPermissionSeeder::class);

    expect(soportesPermissionsOf(Role::PortfolioAnalyst))->toBe(['soportes.review', 'soportes.upload', 'soportes.view'])
        ->and(soportesPermissionsOf(Role::SuperAdmin))->toBe(['soportes.view']);
});
