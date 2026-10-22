<?php

use App\Models\User;
use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;

/*
| Permissions of the module and its two roles, assigned with
| "php artisan comisiones:rol" until Core's team screen can.
*/

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->seed(ComisionesPermissionSeeder::class);
});

/**
 * Permission => sorted role names.
 *
 * @return array<string, list<string>>
 */
function comisionesGrants(): array
{
    return Permission::query()->where('name', 'like', 'comisiones.%')->with('roles')->orderBy('name')->get()
        ->mapWithKeys(fn (Permission $permission) => [
            $permission->name => $permission->roles->pluck('name')->sort()->values()->all(),
        ])
        ->all();
}

it('grants each permission to the roles of the feature document, and seeds again safely', function () {
    $expected = [
        'comisiones.approve' => ['comisiones_aprobador'],
        'comisiones.audit' => ['admin', 'comisiones_aprobador', 'gerente_cartera'],
        'comisiones.calculate' => ['gerente_cartera'],
        'comisiones.capture' => ['gerente_cartera'],
        'comisiones.close' => ['comisiones_aprobador', 'gerente_cartera'],
        'comisiones.parameters' => ['admin', 'gerente_cartera'],
        'comisiones.reports' => ['comisiones_aprobador', 'gerente_cartera'],
        'comisiones.view' => ['comisiones_aprobador', 'comisiones_consulta', 'gerente_cartera'],
    ];

    expect(comisionesGrants())->toBe($expected);

    $this->seed(ComisionesPermissionSeeder::class);

    expect(comisionesGrants())->toBe($expected)
        ->and(SpatieRole::query()->whereIn('name', ModuleRoles::ALL)->count())->toBe(2);
});

it('sends a user with only a module role from the home to the Comisiones screens, not to the no-access page', function (string $role) {
    $this->seed(DatabaseSeeder::class);
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findOrCreate($role, 'web'));

    expect($user->fresh()?->can('core.access'))->toBeTrue();

    $response = $this->actingAs($user)->get('/');
    $location = $response->headers->get('Location');

    // Follow the redirects until the first page that answers.
    for ($hops = 0; $response->isRedirect() && $hops < 5; $hops++) {
        $location = $response->headers->get('Location');
        $response = $this->actingAs($user)->get((string) $location);
    }

    expect((string) parse_url((string) $location, PHP_URL_PATH))->toStartWith('/comisiones')
        ->not->toBe('/sin-acceso');
    $response->assertOk();
})->with([ModuleRoles::APPROVER, ModuleRoles::VIEWER]);

it('assigns and removes a module role by e-mail', function () {
    $user = User::factory()->create(['email' => 'beto.ruiz@segurex.com']);

    $this->artisan('comisiones:rol', ['email' => ' Beto.Ruiz@Segurex.com ', 'rol' => 'comisiones_aprobador'])
        ->expectsOutputToContain('Asignaste el rol comisiones_aprobador a beto.ruiz@segurex.com.')
        ->assertSuccessful();

    expect($user->fresh()?->hasRole('comisiones_aprobador'))->toBeTrue()
        ->and($user->fresh()?->can('comisiones.approve'))->toBeTrue();

    $this->fakes->audit->assertRecorded('comisiones.role.assigned', fn (AuditEntry $entry) => $entry->subjectId === $user->id
        && $entry->context === ['rol' => 'comisiones_aprobador']);

    $this->artisan('comisiones:rol', ['email' => 'beto.ruiz@segurex.com', 'rol' => 'comisiones_aprobador', '--quitar' => true])
        ->expectsOutputToContain('Quitaste el rol comisiones_aprobador a beto.ruiz@segurex.com.')
        ->assertSuccessful();

    expect($user->fresh()?->hasRole('comisiones_aprobador'))->toBeFalse();

    $this->fakes->audit->assertRecorded('comisiones.role.removed');
});

it('assigns only the two module roles', function () {
    $user = User::factory()->create(['email' => 'ana.gomez@segurex.com']);

    $this->artisan('comisiones:rol', ['email' => 'ana.gomez@segurex.com', 'rol' => 'admin'])
        ->expectsOutputToContain('Solo puedes asignar comisiones_aprobador o comisiones_consulta.')
        ->assertFailed();

    expect($user->fresh()?->roles)->toHaveCount(0);
});

it('fails for an e-mail without a user', function () {
    $this->artisan('comisiones:rol', ['email' => 'nadie@segurex.com', 'rol' => 'comisiones_consulta'])
        ->expectsOutputToContain('No hay un usuario activo con el correo nadie@segurex.com.')
        ->assertFailed();

    $this->fakes->audit->assertNothingRecorded();
});
