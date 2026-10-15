<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
    $this->member = TeamUsers::member('Ana Gómez', 'ana.gomez@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);
});

it('removes a user without deleting the row', function () {
    $this->actingAs($this->admin)
        ->delete(route('core.equipo.destroy', $this->member))
        ->assertRedirect(route('core.equipo.index'))
        ->assertSessionHas('status', 'Eliminamos la cuenta de Ana Gómez. Puedes restaurarla desde «Eliminados».');

    $row = User::withTrashed()->findOrFail($this->member->id);
    [$deleted] = TeamUsers::audits('core.user.deleted');

    expect($row->trashed())->toBeTrue()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(User::query()->find($this->member->id))->toBeNull()
        ->and(TeamUsers::roleOf($row))->toBe('analista_cartera')
        ->and($deleted->subject_type)->toBe('user')
        ->and($deleted->subject_id)->toBe((string) $this->member->id)
        ->and($deleted->user_id)->toBe($this->admin->id)
        ->and($deleted->actor)->toBe('laura.admin@segurex.com')
        ->and($deleted->changes)->toEqual(['name' => 'Ana Gómez', 'email' => 'ana.gomez@segurex.com'])
        ->and($deleted->context)->toEqual(['source' => 'equipo']);
});

it('keeps a removed user out of the app', function () {
    $session = [Auth::guard('web')->getName() => $this->member->id];

    $this->withSession($session)->get('/')->assertOk();
    $this->assertAuthenticatedAs($this->member);

    Auth::forgetGuards();
    $this->actingAs($this->admin)->delete(route('core.equipo.destroy', $this->member));
    Auth::forgetGuards();

    $this->withSession($session)->get('/')->assertRedirect('/login');
    $this->assertGuest();
});

it('keeps an admin from removing themselves', function () {
    $this->actingAs($this->admin)
        ->from(route('core.equipo.edit', $this->admin))
        ->delete(route('core.equipo.destroy', $this->admin))
        ->assertRedirect(route('core.equipo.edit', $this->admin))
        ->assertSessionHas('error', 'No puedes eliminar tu propia cuenta.');

    expect(User::query()->find($this->admin->id))->not->toBeNull()
        ->and(TeamUsers::audits('core.user.deleted'))->toBe([]);
});

it('restores a removed user with their role and companies', function () {
    $this->member->delete();

    $this->actingAs($this->admin)
        ->post(route('core.equipo.restore', $this->member))
        ->assertRedirect(route('core.equipo.index', ['estado' => 'eliminados']))
        ->assertSessionHas('status', 'Restauramos la cuenta de Ana Gómez.');

    $user = User::query()->findOrFail($this->member->id);
    [$restored] = TeamUsers::audits('core.user.restored');

    expect(TeamUsers::roleOf($user))->toBe('analista_cartera')
        ->and(TeamUsers::companiesOf($user))->toBe(['SGX'])
        ->and($user->can('core.access'))->toBeTrue()
        ->and($restored->subject_id)->toBe((string) $this->member->id)
        ->and($restored->user_id)->toBe($this->admin->id)
        ->and($restored->changes)->toEqual(['name' => 'Ana Gómez', 'email' => 'ana.gomez@segurex.com'])
        ->and($restored->context)->toEqual(['source' => 'equipo']);
});

it('changes nothing when restoring a user who is not removed', function () {
    $this->actingAs($this->admin)
        ->post(route('core.equipo.restore', $this->member))
        ->assertRedirect(route('core.equipo.index', ['estado' => 'eliminados']))
        ->assertSessionHas('status', 'La cuenta de Ana Gómez no estaba eliminada.');

    expect(TeamUsers::audits('core.user.restored'))->toBe([]);
});

it('answers 404 for a user that does not exist', function () {
    $this->actingAs($this->admin)->delete(route('core.equipo.destroy', 999))->assertNotFound();
    $this->actingAs($this->admin)->post(route('core.equipo.restore', 999))->assertNotFound();
});
