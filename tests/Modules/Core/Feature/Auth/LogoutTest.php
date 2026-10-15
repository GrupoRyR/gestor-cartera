<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Tests\Modules\Core\Support\CoreUsers;

it('is served at POST /logout as core.logout, behind the session, CSRF and auth middleware', function () {
    $route = Route::getRoutes()->getByName('core.logout');

    expect(route('core.logout', absolute: false))->toBe('/logout')
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('web', 'auth')
        ->and(app(Kernel::class)->getMiddlewareGroups()['web'] ?? [])->toContain(PreventRequestForgery::class);
});

it('signs the user out, forgets the session and says so', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    $this->actingAs($user)
        ->withSession(['core.company' => 'SGX', '_token' => 'token-anterior'])
        ->post('/logout')
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'Cerraste sesión.')
        ->assertSessionMissing('core.company');

    $this->assertGuest();

    $entry = AuditLogRecord::query()->sole();

    expect(session()->token())->not->toBe('token-anterior')
        ->and($entry->action)->toBe('core.auth.signed_out')
        ->and($entry->subject_type)->toBe('user')
        ->and($entry->subject_id)->toBe((string) $user->id)
        ->and($entry->user_id)->toBe($user->id);
});

it('shows the goodbye on the sign-in page', function () {
    $this->actingAs(CoreUsers::create(null))
        ->followingRedirects()
        ->post('/logout')
        ->assertOk()
        ->assertViewIs('core::auth.login')
        ->assertSee('Cerraste sesión.');
});

it('sends guests to the sign-in page', function () {
    $this->post('/logout')->assertRedirect('/login');

    expect(AuditLogRecord::query()->exists())->toBeFalse();
});

it('does not sign out through a link', function () {
    $this->actingAs(CoreUsers::create(null))->get('/logout')->assertMethodNotAllowed();

    $this->assertAuthenticated();
});
