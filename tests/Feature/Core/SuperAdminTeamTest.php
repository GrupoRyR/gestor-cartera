<?php

use App\Models\User;
use App\Modules\Core\Application\Team\TeamChangeRefused;
use App\Modules\Core\Application\Team\TeamMemberDetails;
use App\Modules\Core\Application\Team\TeamMembers;
use App\Modules\Core\Application\Team\TeamRefusal;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
    $this->superAdmin = TeamUsers::member('Sofía Súper', 'sofia.super@segurex.com', Role::SuperAdmin);
});

const SUPERADMIN_REFUSAL = 'Solo puedes cambiar a un superadministrador desde la consola, con core:superadmin.';

it('never offers the superadmin role in the team form', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.create'))->assertOk());

    expect($html->count('//select[@name="rol"]/option[@value="superadmin"]'))->toBe(0)
        ->and($html->count('//select[@name="rol"]/option[@value="admin"]'))->toBe(1);
});

it('refuses the superadmin role when creating a user from the team screen', function () {
    $this->actingAs($this->admin)
        ->post(route('core.equipo.store'), [
            'nombre' => 'Pedro Pérez',
            'correo' => 'pedro.perez@segurex.com',
            'rol' => Role::SuperAdmin->value,
            'empresas' => ['SGX'],
            'activo' => '1',
        ])
        ->assertSessionHasErrors('rol');

    expect(User::query()->where('email', 'pedro.perez@segurex.com')->exists())->toBeFalse();
});

it('refuses the superadmin role when editing a user from the team screen', function () {
    $member = TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);

    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $member), [
            'nombre' => 'Ana Gómez',
            'rol' => Role::SuperAdmin->value,
            'empresas' => ['SGX'],
            'activo' => '1',
        ])
        ->assertSessionHasErrors('rol');

    expect(TeamUsers::roleOf($member))->toBe('analista_cartera');
});

it('refuses to change the role of a superadmin from the team screen', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->superAdmin), [
            'nombre' => 'Sofía Súper',
            'rol' => Role::Admin->value,
            'activo' => '1',
        ])
        ->assertSessionHas('error', SUPERADMIN_REFUSAL);

    expect(TeamUsers::roleOf($this->superAdmin))->toBe('superadmin')
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('refuses to change the companies or deactivate a superadmin', function (TeamMemberDetails $details) {
    expect(fn () => app(TeamMembers::class)->update($this->admin, $this->superAdmin, $details))
        ->toThrow(fn (TeamChangeRefused $refused) => expect($refused->reason)->toBe(TeamRefusal::SuperAdminLocked));

    $fresh = User::query()->findOrFail($this->superAdmin->id);

    expect($fresh->hasRole('superadmin'))->toBeTrue()
        ->and($fresh->is_active)->toBeTrue()
        ->and(TeamUsers::companiesOf($fresh))->toBe([]);
})->with([
    'companies' => fn () => new TeamMemberDetails('Sofía Súper', Role::SuperAdmin, [CompanyCode::SGX]),
    'deactivation' => fn () => new TeamMemberDetails('Sofía Súper', Role::SuperAdmin, active: false),
    'another role' => fn () => new TeamMemberDetails('Sofía Súper', Role::PortfolioManager, [CompanyCode::SGX]),
]);

it('refuses to change a superadmin even when a superadmin edits themselves', function () {
    expect(fn () => app(TeamMembers::class)->update($this->superAdmin, $this->superAdmin, new TeamMemberDetails('Sofía Súper', Role::Admin)))
        ->toThrow(TeamChangeRefused::class);

    expect(TeamUsers::roleOf($this->superAdmin))->toBe('superadmin');
});

it('refuses to delete a superadmin from the team screen', function () {
    $this->actingAs($this->admin)
        ->delete(route('core.equipo.destroy', $this->superAdmin))
        ->assertSessionHas('error', SUPERADMIN_REFUSAL);

    expect(User::query()->whereKey($this->superAdmin->id)->exists())->toBeTrue();
});

it('shows a superadmin with every company in the team list', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index'))->assertOk());
    $row = '//tr[td[normalize-space()="sofia.super@segurex.com"]]';

    expect($html->text($row.'/td[3]'))->toBe('Superadministrador')
        ->and($html->text($row.'/td[4]'))->toBe('Todas');
});
