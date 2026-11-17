<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavRegistry;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Fakes\ContractFakes;

/*
 * The Comisiones menu entries name their module, so no user sees two
 * entries with the same label next to Cartera's «Tablero» or Core's
 * «Parámetros». Every module's permissions are seeded, as in production.
 */

beforeEach(function () {
    ContractFakes::install($this->app);
    $this->seed(DatabaseSeeder::class);
});

it('names the dashboard and the parameters after the module', function () {
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findOrCreate(Role::PortfolioManager->value, 'web'));
    $labels = collect(app(NavRegistry::class)->visibleTo($user))->pluck('label', 'key');

    expect($labels['comisiones.tablero'])->toBe('Tablero de comisiones')
        ->and($labels['comisiones.parametros'])->toBe('Parámetros de comisiones');
});

it('never shows a user two menu entries with the same label', function (Role $role) {
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
    $labels = collect(app(NavRegistry::class)->visibleTo($user))->pluck('label');

    expect($labels->duplicates()->values()->all())->toBe([]);
})->with(array_values(array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::SuperAdmin)));

it('never shows the superadmin two entries with the same label in the same group', function () {
    // The superadmin sees Cartera and Asesores together, so «Clientes» and
    // «Equipo» repeat across groups; inside a group they must not.
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findOrCreate(Role::SuperAdmin->value, 'web'));

    foreach (app(NavRegistry::class)->groupedFor($user) as $group => $items) {
        expect(collect($items)->pluck('label')->duplicates()->values()->all())->toBe([], $group);
    }
});
