<?php

use App\Support\Modules\ModuleRegistry;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Seed from the fixture modules instead of app/Modules.
    $this->app->instance(
        ModuleRegistry::class,
        new ModuleRegistry(base_path('tests/Fixtures/Modules'), 'Tests\\Fixtures\\Modules'),
    );
});

it('runs the permission seeder of every module', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Permission::where('name', 'demo.view')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(Role::findByName('analista_cartera', 'web')->hasPermissionTo('demo.view'))->toBeTrue()
        ->and(Role::findByName('gerente_cartera', 'web')->hasPermissionTo('demo.manage'))->toBeTrue()
        ->and(Role::findByName('analista_cartera', 'web')->hasPermissionTo('demo.manage'))->toBeFalse();
});

it('can seed again without duplicating permissions or roles', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Permission::where('name', 'demo.view')->count())->toBe(1)
        ->and(Role::where('name', 'analista_cartera')->count())->toBe(1);
});

it('runs the module demo seeders only in the local environment', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::where('name', 'demo_local')->exists())->toBeFalse();

    $this->app['env'] = 'local';
    $this->seed(DatabaseSeeder::class);

    expect(Role::where('name', 'demo_local')->exists())->toBeTrue();
});
