<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The roles that hold $permission after the seeder ran, by name.
 *
 * @return list<string>
 */
function cobroRolesWith(string $permission): array
{
    return SpatieRole::query()
        ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

it('gives cobro.export to the roles that see the report, and never to advisors', function () {
    $this->seed(CobroPermissionSeeder::class);

    $expected = array_map(fn (Role $role): string => $role->value, [Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst, Role::SuperAdmin]);
    sort($expected);

    expect(cobroRolesWith('cobro.export'))->toBe($expected)
        ->and(cobroRolesWith('cobro.export'))->toBe(cobroRolesWith('cobro.view'))
        ->and(cobroRolesWith('cobro.export'))->not->toContain(Role::SalesAdvisor->value);
});

it('can run again without duplicating the permission', function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->seed(CobroPermissionSeeder::class);

    expect(cobroRolesWith('cobro.export'))->toHaveCount(4);
});
