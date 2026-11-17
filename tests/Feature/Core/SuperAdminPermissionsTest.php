<?php

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

it('labels the superadmin role in Spanish', function () {
    expect(Role::SuperAdmin->value)->toBe('superadmin')
        ->and(Role::SuperAdmin->label())->toBe('Superadministrador');
});

it('never offers the superadmin role as an option', function () {
    expect(Role::options())->not->toHaveKey('superadmin')
        ->and(Role::options())->toHaveKey('admin');
});

it('gives the superadmin every permission of every module except the excluded ones', function () {
    $this->seed(DatabaseSeeder::class);

    $superAdmin = SpatieRole::findByName(Role::SuperAdmin->value, 'web');
    $missing = Permission::query()
        ->orderBy('name')
        ->pluck('name')
        ->reject(fn (string $name): bool => $superAdmin->hasPermissionTo($name))
        ->values()
        ->all();

    // A default (start_on_mine) and the separation of duties: commitments
    // stay with whoever collects, approvals with the approver.
    expect($missing)->toBe(['cartera.clients.start_on_mine', 'cartera.commitments.write', 'comisiones.approve']);
});

it('keeps the excluded permissions with their own roles', function (string $permission, string $role) {
    $this->seed(DatabaseSeeder::class);

    expect(SpatieRole::findByName($role, 'web')->hasPermissionTo($permission))->toBeTrue()
        ->and(SpatieRole::findByName(Role::SuperAdmin->value, 'web')->hasPermissionTo($permission))->toBeFalse();
})->with([
    ['cartera.clients.start_on_mine', 'analista_cartera'],
    ['cartera.commitments.write', 'analista_cartera'],
    ['comisiones.approve', 'comisiones_aprobador'],
]);

it('lets each module seeder exclude permissions from the superadmin', function () {
    $seeder = new class extends PermissionSeeder
    {
        public function permissions(): array
        {
            return ['demo.read' => [Role::Admin], 'demo.default' => [Role::Admin]];
        }

        protected function superAdminExcludes(): array
        {
            return ['demo.default'];
        }
    };

    $seeder->run();

    $superAdmin = SpatieRole::findByName(Role::SuperAdmin->value, 'web');

    expect($superAdmin->hasPermissionTo('demo.read'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('demo.default'))->toBeFalse()
        ->and(SpatieRole::findByName(Role::Admin->value, 'web')->hasPermissionTo('demo.default'))->toBeTrue();
});

it('gives the superadmin a permission even when the module lists no role for it', function () {
    $seeder = new class extends PermissionSeeder
    {
        public function permissions(): array
        {
            return ['demo.orphan' => []];
        }
    };

    $seeder->run();

    expect(SpatieRole::findByName(Role::SuperAdmin->value, 'web')->hasPermissionTo('demo.orphan'))->toBeTrue();
});
