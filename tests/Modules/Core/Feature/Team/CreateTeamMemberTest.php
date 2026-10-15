<?php

use App\Models\User;
use App\Modules\Core\Application\Team\TeamChangeRefused;
use App\Modules\Core\Application\Team\TeamMemberDetails;
use App\Modules\Core\Application\Team\TeamMembers;
use App\Modules\Core\Application\Team\TeamRefusal;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
});

it('shows the form to pre-register a person', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.create'))->assertOk());
    $form = $html->one('//main//form[@method="POST"]');
    $roles = array_map(fn (DOMElement $option): string => Html::clean($option->textContent), $html->all('//select[@name="rol"]/option'));

    expect($html->text('//h1'))->toBe('Nuevo usuario')
        ->and($form->getAttribute('action'))->toBe(route('core.equipo.store'))
        ->and($html->labelledControl('Nombre')->getAttribute('name'))->toBe('nombre')
        ->and($html->labelledControl('Correo')->getAttribute('type'))->toBe('email')
        ->and($html->labelledControl('Rol')->getAttribute('name'))->toBe('rol')
        ->and($roles)->toBe(['Sin rol', ...array_values(Role::options())])
        ->and($html->labelledControl('Segurex Latam (SGX)')->getAttribute('value'))->toBe('SGX')
        ->and($html->labelledControl('Soluciones y Derivados (SDC)')->getAttribute('name'))->toBe('empresas[]')
        ->and($html->text('//fieldset/legend'))->toBe('Empresas');
});

it('pre-registers a person without a role', function () {
    $this->actingAs($this->admin)
        ->post(route('core.equipo.store'), ['nombre' => 'Ana Gómez', 'correo' => 'ana.gomez@segurex.com', 'rol' => ''])
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHas('status', 'Registramos a Ana Gómez. Podrá ingresar con Microsoft usando ese correo.');

    $user = User::query()->where('email', 'ana.gomez@segurex.com')->sole();
    [$created] = TeamUsers::audits('core.user.created');

    expect($user->name)->toBe('Ana Gómez')
        ->and($user->is_active)->toBeTrue()
        ->and($user->microsoft_id)->toBeNull()
        ->and(TeamUsers::roleOf($user))->toBeNull()
        ->and(TeamUsers::companiesOf($user))->toBe([])
        ->and($created->subject_type)->toBe('user')
        ->and($created->subject_id)->toBe((string) $user->id)
        ->and($created->user_id)->toBe($this->admin->id)
        ->and($created->changes)->toBe([
            'name' => 'Ana Gómez',
            'email' => 'ana.gomez@segurex.com',
            'role' => null,
            'companies' => [],
        ])
        ->and($created->context)->toBe(['source' => 'equipo']);
});

it('pre-registers a person with a role and companies', function () {
    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Pedro Ruiz',
        'correo' => 'pedro.ruiz@segurex.com',
        'rol' => Role::PortfolioAnalyst->value,
        'empresas' => ['SGX', 'SDC'],
    ])->assertRedirect(route('core.equipo.index'));

    $user = User::query()->where('email', 'pedro.ruiz@segurex.com')->sole();

    expect(TeamUsers::roleOf($user))->toBe('analista_cartera')
        ->and(TeamUsers::companiesOf($user))->toBe(['SDC', 'SGX'])
        ->and($user->can('core.access'))->toBeTrue()
        ->and(TeamUsers::audits('core.user.created')[0]->changes)->toMatchArray([
            'role' => 'analista_cartera',
            'companies' => ['SDC', 'SGX'],
        ]);
});

it('stores the e-mail trimmed and in lowercase', function () {
    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Ana Gómez',
        'correo' => '  Ana.Gomez@Segurex.COM ',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'ana.gomez@segurex.com')->exists())->toBeTrue();
});

it('rejects an e-mail that already belongs to a user', function () {
    TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);

    $this->actingAs($this->admin)
        ->from(route('core.equipo.create'))
        ->post(route('core.equipo.store'), ['nombre' => 'Otra Ana', 'correo' => 'ANA.GOMEZ@segurex.com'])
        ->assertRedirect(route('core.equipo.create'))
        ->assertSessionHasErrors(['correo' => 'Ya existe un usuario con ese correo.']);

    expect(User::withTrashed()->where('email', 'ana.gomez@segurex.com')->sole()->name)->toBe('Ana Gómez')
        ->and(TeamUsers::audits('core.user.created'))->toBe([]);
});

it('refuses an e-mail taken between the validation and the save', function () {
    TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', null);

    expect(fn () => app(TeamMembers::class)->create('Ana.Gomez@segurex.com', new TeamMemberDetails('Otra Ana', null)))
        ->toThrow(fn (TeamChangeRefused $refused) => expect($refused->reason)->toBe(TeamRefusal::EmailTaken))
        ->and(User::withTrashed()->where('email', 'ana.gomez@segurex.com')->sole()->name)->toBe('Ana Gómez')
        ->and(TeamUsers::audits('core.user.created'))->toBe([]);
});

it('restores a removed person in the same row and applies the form', function () {
    $removed = TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX], active: false, deleted: true);
    $users = User::withTrashed()->count();

    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Ana María Gómez',
        'correo' => 'Ana.Gomez@segurex.com',
        'rol' => Role::PortfolioAnalyst->value,
        'empresas' => ['SDC'],
    ])
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHas('status', 'Restauramos la cuenta eliminada de Ana María Gómez con los datos del formulario.');

    $user = User::query()->findOrFail($removed->id);
    [$restored] = TeamUsers::audits('core.user.restored');
    [$updated] = TeamUsers::audits('core.user.updated');

    expect(User::withTrashed()->count())->toBe($users)
        ->and($user->name)->toBe('Ana María Gómez')
        ->and($user->is_active)->toBeTrue()
        ->and(TeamUsers::roleOf($user))->toBe('analista_cartera')
        ->and(TeamUsers::companiesOf($user))->toBe(['SDC'])
        ->and(TeamUsers::audits('core.user.created'))->toBe([])
        ->and($restored->subject_id)->toBe((string) $removed->id)
        ->and($restored->user_id)->toBe($this->admin->id)
        ->and($restored->context)->toBe(['source' => 'equipo'])
        ->and($updated->subject_id)->toBe((string) $removed->id)
        ->and($updated->changes)->toBe([
            'name' => ['old' => 'Ana Gómez', 'new' => 'Ana María Gómez'],
            'role' => ['old' => 'asesor', 'new' => 'analista_cartera'],
            'companies' => ['old' => ['SGX'], 'new' => ['SDC']],
            'active' => ['old' => false, 'new' => true],
        ]);
});

it('ignores the companies of an admin', function () {
    $this->actingAs($this->admin)->post(route('core.equipo.store'), [
        'nombre' => 'Jorge TI',
        'correo' => 'jorge.ti@segurex.com',
        'rol' => Role::Admin->value,
        'empresas' => ['SGX'],
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jorge.ti@segurex.com')->sole();

    expect(TeamUsers::roleOf($user))->toBe('admin')
        ->and(TeamUsers::companiesOf($user))->toBe([]);
});

it('asks for at least one company for every role but admin', function () {
    $this->actingAs($this->admin)
        ->post(route('core.equipo.store'), ['nombre' => 'Pedro Ruiz', 'correo' => 'pedro.ruiz@segurex.com', 'rol' => Role::SalesAdvisor->value])
        ->assertSessionHasErrors(['empresas' => 'Elige al menos una empresa para este rol.']);

    expect(User::query()->where('email', 'pedro.ruiz@segurex.com')->exists())->toBeFalse();
});

it('explains validation errors in Spanish', function (array $input, array $errors) {
    $this->actingAs($this->admin)
        ->post(route('core.equipo.store'), $input)
        ->assertSessionHasErrors($errors);
})->with([
    'nothing sent' => [[], [
        'nombre' => 'El campo nombre es obligatorio.',
        'correo' => 'El campo correo es obligatorio.',
    ]],
    'bad e-mail and role' => [['nombre' => 'Ana', 'correo' => 'no-es-un-correo', 'rol' => 'jefe'], [
        'correo' => 'El campo correo debe ser una dirección de correo válida.',
        'rol' => 'El valor elegido en rol no es válido.',
    ]],
    'unknown company' => [['nombre' => 'Ana', 'correo' => 'ana@segurex.com', 'rol' => 'asesor', 'empresas' => ['XYZ']], [
        'empresas.0' => 'El valor elegido en empresa no es válido.',
    ]],
]);
