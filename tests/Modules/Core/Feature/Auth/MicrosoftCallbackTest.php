<?php

use App\Models\User;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Socialite\Two\InvalidStateException;
use Tests\Modules\Core\Support\FakeIdentityProvider;

const MICROSOFT_CALLBACK = '/auth/microsoft/callback?code=codigo-de-prueba&state=estado-de-prueba';

/**
 * Asserts the callback sends the guest back to /login with $message and
 * audits the rejection $reason for $email.
 */
function assertSignInRejected(string $reason, string $message, string $email = 'ana.gomez@segurex.com'): void
{
    test()->get(MICROSOFT_CALLBACK)->assertRedirect('/login')->assertSessionHas('error', $message);
    test()->assertGuest();

    $entry = AuditLogRecord::query()->sole();

    expect($entry->action)->toBe('core.auth.rejected')
        ->and($entry->context)->toEqual(['reason' => $reason, 'email' => $email])
        ->and($entry->user_id)->toBeNull();
}

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    config(['services.microsoft.tenant' => FakeIdentityProvider::TENANT]);
    $this->microsoft = FakeIdentityProvider::install($this->app);
});

describe('a successful sign-in', function () {
    it('creates a new user without a role, signs them in and sends them home', function () {
        $this->microsoft->signsIn(FakeIdentityProvider::identity('Ana.Gomez@Segurex.com', 'oid-ana', name: 'Ana Gómez'));

        $this->get(MICROSOFT_CALLBACK)->assertRedirect('/');

        $user = User::query()->sole();
        $created = AuditLogRecord::query()->where('action', 'core.user.created')->sole();

        expect($user->email)->toBe('ana.gomez@segurex.com')
            ->and($user->name)->toBe('Ana Gómez')
            ->and($user->microsoft_id)->toBe('oid-ana')
            ->and($user->is_active)->toBeTrue()
            ->and($user->getRoleNames()->all())->toBe([])
            ->and(AuditLogRecord::query()->orderBy('id')->pluck('action')->all())->toBe(['core.user.created', 'core.auth.signed_in'])
            ->and($created->subject_type)->toBe('user')
            ->and($created->subject_id)->toBe((string) $user->id)
            ->and($created->context)->toEqual(['source' => 'microsoft'])
            ->and(AuditLogRecord::query()->where('action', 'core.auth.signed_in')->sole()->user_id)->toBe($user->id);

        $this->assertAuthenticatedAs($user);
    });

    it('lands a new user on the screen that asks for access', function () {
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com'));

        $this->followingRedirects()->get(MICROSOFT_CALLBACK)->assertOk()->assertSee('Todavía no tienes acceso');
    });

    it('starts a new session', function () {
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com'));
        $before = session()->getId();

        $this->get(MICROSOFT_CALLBACK);

        expect(session()->getId())->not->toBe($before);
    });

    it('returns to the page the user asked for before signing in', function () {
        $this->get('/sin-acceso')->assertRedirect('/login');
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com'));

        $this->get(MICROSOFT_CALLBACK)->assertRedirect('/sin-acceso');
    });

    it('recognizes a registered user by e-mail in any case, links Microsoft and updates the name', function () {
        $user = User::factory()->withoutMicrosoftId()->create(['email' => 'ana.gomez@segurex.com', 'name' => 'Ana']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ANA.Gomez@Segurex.com', 'oid-ana', name: 'Ana María Gómez'));

        $this->get(MICROSOFT_CALLBACK)->assertRedirect('/');

        $user->refresh();
        $updated = AuditLogRecord::query()->where('action', 'core.user.updated')->sole();

        expect(User::query()->count())->toBe(1)
            ->and($user->microsoft_id)->toBe('oid-ana')
            ->and($user->name)->toBe('Ana María Gómez')
            ->and($updated->subject_id)->toBe((string) $user->id)
            ->and($updated->changes)->toEqual([
                'microsoft_id' => ['old' => null, 'new' => 'oid-ana'],
                'name' => ['old' => 'Ana', 'new' => 'Ana María Gómez'],
            ])
            ->and($updated->context)->toEqual(['source' => 'microsoft'])
            ->and(AuditLogRecord::query()->where('action', 'core.user.created')->exists())->toBeFalse();

        $this->assertAuthenticatedAs($user);
    });

    it('changes nothing for a registered user whose profile did not change', function () {
        $user = User::factory()->create(['email' => 'ana.gomez@segurex.com', 'name' => 'Ana Gómez', 'microsoft_id' => 'oid-ana']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana', name: 'Ana Gómez'));

        $this->get(MICROSOFT_CALLBACK)->assertRedirect('/');

        expect(AuditLogRecord::query()->pluck('action')->all())->toBe(['core.auth.signed_in']);
        $this->assertAuthenticatedAs($user);
    });
});

describe('a rejected sign-in', function () {
    it('refuses accounts of another directory', function (?string $tenant) {
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', tenantId: $tenant));

        assertSignInRejected('tenant', 'Esa cuenta de Microsoft no es del Grupo RYR. Ingresa con la cuenta de tu empresa.');

        expect(User::withTrashed()->count())->toBe(0);
    })->with(['another tenant' => ['tenant-ajeno'], 'no tid claim' => [null], 'empty tid claim' => ['']]);

    it('refuses everyone while no tenant is configured', function (?string $configured) {
        config(['services.microsoft.tenant' => $configured]);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', tenantId: $configured));

        assertSignInRejected('tenant', 'Esa cuenta de Microsoft no es del Grupo RYR. Ingresa con la cuenta de tu empresa.');
    })->with(['null' => [null], 'empty' => [''], 'spaces' => ['  ']]);

    it('refuses guest accounts of the tenant (audit S-17)', function () {
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', guest: true));

        assertSignInRejected('guest', 'Las cuentas de invitado no pueden entrar al gestor de cartera. Ingresa con la cuenta de tu empresa.');

        expect(User::withTrashed()->count())->toBe(0);
    });

    it('refuses a registered user who comes back as a guest, and changes nothing', function () {
        $user = User::factory()->create(['email' => 'ana.gomez@segurex.com', 'name' => 'Ana', 'microsoft_id' => 'oid-ana']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana', name: 'Ana Gómez', guest: true));

        assertSignInRejected('guest', 'Las cuentas de invitado no pueden entrar al gestor de cartera. Ingresa con la cuenta de tu empresa.');

        expect($user->refresh()->name)->toBe('Ana');
    });

    it('keeps a removed user out and removed', function () {
        $user = User::factory()->create(['email' => 'ana.gomez@segurex.com', 'microsoft_id' => 'oid-ana']);
        $user->delete();
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana'));

        assertSignInRejected('deleted', 'Tu cuenta fue retirada del gestor de cartera. Habla con TI si necesitas volver a entrar.');

        expect($user->refresh()->trashed())->toBeTrue();
    });

    it('keeps a deactivated user out', function () {
        $user = User::factory()->inactive()->withoutMicrosoftId()->create(['email' => 'ana.gomez@segurex.com', 'name' => 'Ana']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana', name: 'Ana Gómez'));

        assertSignInRejected('inactive', 'Tu cuenta está desactivada. Habla con TI si crees que es un error.');

        expect($user->refresh()->microsoft_id)->toBeNull()
            ->and($user->name)->toBe('Ana');
    });

    it('refuses an e-mail registered with another Microsoft account', function () {
        $user = User::factory()->create(['email' => 'ana.gomez@segurex.com', 'microsoft_id' => 'oid-otra']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana'));

        assertSignInRejected('identity_mismatch', 'Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.');

        expect($user->refresh()->microsoft_id)->toBe('oid-otra');
    });

    it('refuses a Microsoft account registered with another e-mail', function () {
        $user = User::factory()->create(['email' => 'ana.antes@segurex.com', 'microsoft_id' => 'oid-ana']);
        $this->microsoft->signsIn(FakeIdentityProvider::identity('ana.gomez@segurex.com', 'oid-ana'));

        assertSignInRejected('identity_mismatch', 'Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.');

        expect(User::withTrashed()->pluck('email')->all())->toBe([$user->email]);
    });
});

it('audits a failure of Microsoft and asks the user to try again', function (Exception $failure) {
    Exceptions::fake();
    $this->microsoft->fails($failure);

    $this->get(MICROSOFT_CALLBACK)
        ->assertRedirect('/login')
        ->assertSessionHas('error', 'No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.');

    $this->assertGuest();
    expect(User::withTrashed()->count())->toBe(0);

    // The error class only: a message from Microsoft may echo the code or
    // the state of the request.
    $entry = AuditLogRecord::query()->sole();

    expect($entry->action)->toBe('core.auth.failed')
        ->and($entry->user_id)->toBeNull()
        ->and($entry->context)->toEqual(['error' => class_basename($failure)]);
})->with([
    'invalid state' => [new InvalidStateException('state mismatch')],
    'failed request' => [new RuntimeException('token endpoint answered 500')],
]);

it('reports unexpected failures, but not an expired or replayed state', function () {
    Exceptions::fake();

    $this->microsoft->fails(new InvalidStateException);
    $this->get(MICROSOFT_CALLBACK);
    Exceptions::assertNotReported(InvalidStateException::class);

    $this->microsoft->fails(new RuntimeException('token endpoint answered 500'));
    $this->get(MICROSOFT_CALLBACK);
    Exceptions::assertReported(RuntimeException::class);
});

it('sends signed-in users home without asking Microsoft', function () {
    $this->actingAs(User::factory()->create())->get(MICROSOFT_CALLBACK)->assertRedirect('/');
});
