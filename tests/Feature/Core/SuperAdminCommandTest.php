<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Illuminate\Support\Str;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
});

/**
 * A user who already signed in with Microsoft (invented object id).
 */
function signedInMember(string $email, ?Role $role, bool $active = true): User
{
    $user = TeamUsers::member('Sofía Súper', $email, $role, [CompanyCode::SGX], $active);
    $user->forceFill(['microsoft_id' => (string) Str::uuid()])->save();

    return $user;
}

it('makes a person superadmin, replacing their role, and audits it', function () {
    $user = signedInMember('sofia.super@segurex.com', Role::PortfolioAnalyst);

    $this->artisan('core:superadmin', ['email' => '  Sofia.Super@Segurex.com '])
        ->expectsOutputToContain('Ahora sofia.super@segurex.com es superadministrador.')
        ->assertSuccessful();

    [$entry] = TeamUsers::audits('core.user.superadmin_granted');

    expect(User::query()->findOrFail($user->id)->getRoleNames()->all())->toBe(['superadmin'])
        ->and($entry->subject_type)->toBe('user')
        ->and($entry->subject_id)->toBe((string) $user->id)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->changes)->toEqual(['role' => ['old' => 'analista_cartera', 'new' => 'superadmin']])
        ->and($entry->context)->toEqual(['source' => 'consola']);
});

it('removes the superadmin role with --quitar, leaving the person without a role, and audits it', function () {
    $user = signedInMember('sofia.super@segurex.com', Role::SuperAdmin);

    $this->artisan('core:superadmin', ['email' => 'sofia.super@segurex.com', '--quitar' => true])
        ->expectsOutputToContain('Quitaste el rol de superadministrador a sofia.super@segurex.com.')
        ->assertSuccessful();

    [$entry] = TeamUsers::audits('core.user.superadmin_revoked');

    expect(User::query()->findOrFail($user->id)->getRoleNames()->all())->toBe([])
        ->and($entry->changes)->toEqual(['role' => ['old' => 'superadmin', 'new' => null]])
        ->and($entry->context)->toEqual(['source' => 'consola']);
});

it('refuses a person who never signed in with Microsoft', function () {
    // Pre-registered from the team screen: no Microsoft object id yet.
    $user = TeamUsers::member('Pedro Pérez', 'pedro.perez@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);
    $user->forceFill(['microsoft_id' => null])->save();

    $this->artisan('core:superadmin', ['email' => 'pedro.perez@segurex.com'])
        ->expectsOutputToContain('pedro.perez@segurex.com todavía no ha entrado con Microsoft.')
        ->assertFailed();

    $this->artisan('core:superadmin', ['email' => 'nadie@segurex.com'])
        ->expectsOutputToContain('nadie@segurex.com todavía no ha entrado con Microsoft.')
        ->assertFailed();

    expect(TeamUsers::roleOf($user))->toBe('analista_cartera')
        ->and(TeamUsers::audits('core.user.superadmin_granted'))->toBe([]);
});

it('refuses an inactive or deleted person', function () {
    $inactive = signedInMember('inactiva@segurex.com', Role::PortfolioAnalyst, active: false);
    $deleted = signedInMember('borrada@segurex.com', Role::PortfolioAnalyst);
    $deleted->delete();

    $this->artisan('core:superadmin', ['email' => 'inactiva@segurex.com'])
        ->expectsOutputToContain('La cuenta de inactiva@segurex.com está desactivada.')
        ->assertFailed();

    $this->artisan('core:superadmin', ['email' => 'borrada@segurex.com'])
        ->expectsOutputToContain('borrada@segurex.com todavía no ha entrado con Microsoft.')
        ->assertFailed();

    expect(TeamUsers::roleOf($inactive))->toBe('analista_cartera')
        ->and(TeamUsers::roleOf($deleted))->toBe('analista_cartera');
});

it('changes nothing when the person already has the role, or lacks it with --quitar', function () {
    signedInMember('sofia.super@segurex.com', Role::SuperAdmin);
    $analyst = signedInMember('ana.gomez@segurex.com', Role::PortfolioAnalyst);

    $this->artisan('core:superadmin', ['email' => 'sofia.super@segurex.com'])
        ->expectsOutputToContain('sofia.super@segurex.com ya es superadministrador.')
        ->assertSuccessful();

    $this->artisan('core:superadmin', ['email' => 'ana.gomez@segurex.com', '--quitar' => true])
        ->expectsOutputToContain('ana.gomez@segurex.com no es superadministrador.')
        ->assertFailed();

    expect(TeamUsers::roleOf($analyst))->toBe('analista_cartera')
        ->and(TeamUsers::audits('core.user.superadmin_granted'))->toBe([])
        ->and(TeamUsers::audits('core.user.superadmin_revoked'))->toBe([]);
});
