<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
    $this->member = TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);
});

/**
 * The edit form of the member as the browser sends it unchanged: an
 * unchecked box is not sent at all.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function teamMemberForm(array $changes = []): array
{
    return [
        'nombre' => 'Ana Gómez',
        'rol' => Role::PortfolioAnalyst->value,
        'empresas' => ['SGX'],
        'activo' => '1',
        ...$changes,
    ];
}

it('shows the edit form with the current values', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $this->member))->assertOk());

    expect($html->text('//h1'))->toBe('Ana Gómez')
        ->and($html->one('//main//form[input[@name="_method"][@value="PUT"]]')->getAttribute('action'))->toBe(route('core.equipo.update', $this->member))
        ->and($html->labelledControl('Nombre')->getAttribute('value'))->toBe('Ana Gómez')
        ->and($html->one('//select[@name="rol"]/option[@selected]')->getAttribute('value'))->toBe('analista_cartera')
        ->and($html->labelledControl('Segurex Latam (SGX)')->hasAttribute('checked'))->toBeTrue()
        ->and($html->labelledControl('Soluciones y Derivados (SDC)')->hasAttribute('checked'))->toBeFalse()
        ->and($html->labelledControl('Cuenta activa')->hasAttribute('checked'))->toBeTrue();
});

it('asks for confirmation before removing a user', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $this->member)));
    $dialog = $html->byId('eliminar-usuario');

    expect($html->one('//button[@data-modal-open="eliminar-usuario"]')->textContent)->toContain('Eliminar usuario')
        ->and($dialog->tagName)->toBe('dialog')
        ->and($html->one('//dialog[@id="eliminar-usuario"]//form[input[@name="_method"][@value="DELETE"]]')->getAttribute('action'))
        ->toBe(route('core.equipo.destroy', $this->member));
});

it('does not offer an admin to remove themselves', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.edit', $this->admin))->assertOk());

    expect($html->count('//*[@data-modal-open="eliminar-usuario"]'))->toBe(0);
});

it('changes the role and the companies', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->member), teamMemberForm([
            'rol' => Role::SalesManager->value,
            'empresas' => ['SDC', 'SGX'],
        ]))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHas('status', 'Guardamos los cambios de Ana Gómez.');

    [$updated] = TeamUsers::audits('core.user.updated');

    expect(TeamUsers::roleOf($this->member))->toBe('gerente_comercial')
        ->and(TeamUsers::companiesOf($this->member))->toBe(['SDC', 'SGX'])
        ->and($updated->subject_id)->toBe((string) $this->member->id)
        ->and($updated->user_id)->toBe($this->admin->id)
        ->and($updated->context)->toEqual(['source' => 'equipo'])
        ->and($updated->changes)->toEqual([
            'role' => ['old' => 'analista_cartera', 'new' => 'gerente_comercial'],
            'companies' => ['old' => ['SGX'], 'new' => ['SDC', 'SGX']],
        ]);
});

it('removes the role', function () {
    $this->actingAs($this->admin)->put(route('core.equipo.update', $this->member), teamMemberForm(['rol' => '']));

    expect(TeamUsers::roleOf($this->member))->toBeNull()
        ->and(User::query()->findOrFail($this->member->id)->can('core.access'))->toBeFalse()
        ->and(TeamUsers::companiesOf($this->member))->toBe(['SGX'])
        ->and(TeamUsers::audits('core.user.updated')[0]->changes)->toEqual([
            'role' => ['old' => 'analista_cartera', 'new' => null],
        ]);
});

it('deactivates a user, whose unchecked box is not sent', function () {
    $form = teamMemberForm();
    unset($form['activo']);

    $this->actingAs($this->admin)->put(route('core.equipo.update', $this->member), $form);

    expect(User::query()->findOrFail($this->member->id)->is_active)->toBeFalse()
        ->and(TeamUsers::audits('core.user.updated')[0]->changes)->toEqual([
            'active' => ['old' => true, 'new' => false],
        ]);
});

it('renames a user and records only that change', function () {
    $this->actingAs($this->admin)->put(route('core.equipo.update', $this->member), teamMemberForm(['nombre' => 'Ana María Gómez']));

    expect(User::query()->findOrFail($this->member->id)->name)->toBe('Ana María Gómez')
        ->and(TeamUsers::audits('core.user.updated')[0]->changes)->toEqual([
            'name' => ['old' => 'Ana Gómez', 'new' => 'Ana María Gómez'],
        ]);
});

it('records nothing when nothing changes', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->member), teamMemberForm())
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHas('status', 'Guardamos los cambios de Ana Gómez.');

    expect(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('keeps no companies for an admin', function () {
    $this->actingAs($this->admin)->put(route('core.equipo.update', $this->member), teamMemberForm(['rol' => Role::Admin->value]));

    expect(TeamUsers::roleOf($this->member))->toBe('admin')
        ->and(TeamUsers::companiesOf($this->member))->toBe([])
        ->and(TeamUsers::audits('core.user.updated')[0]->changes)->toEqual([
            'role' => ['old' => 'analista_cartera', 'new' => 'admin'],
            'companies' => ['old' => ['SGX'], 'new' => []],
        ]);
});

it('asks for at least one company for every role but admin', function () {
    $form = teamMemberForm(['rol' => Role::SalesAdvisor->value]);
    unset($form['empresas']);

    $this->actingAs($this->admin)
        ->from(route('core.equipo.edit', $this->member))
        ->put(route('core.equipo.update', $this->member), $form)
        ->assertRedirect(route('core.equipo.edit', $this->member))
        ->assertSessionHasErrors(['empresas' => 'Elige al menos una empresa para este rol.']);

    expect(TeamUsers::roleOf($this->member))->toBe('analista_cartera')
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
});

it('keeps an admin from locking themselves out', function (array $changes, string $message) {
    $form = [...teamMemberForm(['nombre' => 'Laura Admin', 'rol' => Role::Admin->value, 'empresas' => []]), ...$changes];
    $form = array_filter($form, fn ($value): bool => $value !== false);

    $this->actingAs($this->admin)
        ->from(route('core.equipo.edit', $this->admin))
        ->put(route('core.equipo.update', $this->admin), $form)
        ->assertRedirect(route('core.equipo.edit', $this->admin))
        ->assertSessionHas('error', $message);

    $admin = User::query()->findOrFail($this->admin->id);

    expect($admin->is_active)->toBeTrue()
        ->and(TeamUsers::roleOf($admin))->toBe('admin')
        ->and(TeamUsers::audits('core.user.updated'))->toBe([]);
})->with([
    'deactivate' => [['activo' => false], 'No puedes desactivar tu propia cuenta.'],
    'take another role' => [['rol' => Role::PortfolioManager->value, 'empresas' => ['SGX']], 'No puedes quitarte el rol de administrador.'],
    'drop the role' => [['rol' => ''], 'No puedes quitarte el rol de administrador.'],
]);

it('lets an admin rename themselves', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->admin), teamMemberForm(['nombre' => 'Laura TI', 'rol' => Role::Admin->value, 'empresas' => []]))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHasNoErrors();

    expect(User::query()->findOrFail($this->admin->id)->name)->toBe('Laura TI');
});

it('does not find a removed user', function () {
    $this->member->delete();

    $this->actingAs($this->admin)->get(route('core.equipo.edit', $this->member))->assertNotFound();
    $this->actingAs($this->admin)->put(route('core.equipo.update', $this->member), teamMemberForm())->assertNotFound();
});

it('explains validation errors in Spanish', function () {
    $this->actingAs($this->admin)
        ->put(route('core.equipo.update', $this->member), ['nombre' => '', 'rol' => 'jefe'])
        ->assertSessionHasErrors([
            'nombre' => 'El campo nombre es obligatorio.',
            'rol' => 'El valor elegido en rol no es válido.',
        ]);
});
