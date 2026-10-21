<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\TestCase;

/*
| routes/web.php registers the demo sign-in only when the application boots in
| local. Tests boot in "testing", so each test switches the environment and
| loads the base routes again, as the framework does on boot. Outside
| "testing" the CSRF check is on, so the sign-in posts the session token like
| the form does.
*/
function bootDevRoutesIn(string $environment): void
{
    app()['env'] = $environment;

    Route::middleware('web')->group(base_path('routes/web.php'));
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * @return TestResponse<Response>
 */
function postDemoSignIn(TestCase $test, string $uri, string $address = '127.0.0.1'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $address])
        ->withSession(['_token' => 'demo-csrf-token'])
        ->post($uri, ['_token' => 'demo-csrf-token']);
}

it('does not register the demo sign-in in the testing environment', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    expect(Route::has('dev.entrar'))->toBeFalse()
        ->and(Route::has('dev.entrar.usuario'))->toBeFalse();

    $this->get('/dev/entrar')->assertNotFound();
    postDemoSignIn($this, "/dev/entrar/{$user->id}")->assertNotFound();
    $this->assertGuest();
});

it('does not register the demo sign-in in production', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    bootDevRoutesIn('production');

    expect(Route::has('dev.entrar.usuario'))->toBeFalse();

    $this->get('/dev/entrar')->assertNotFound();
    postDemoSignIn($this, "/dev/entrar/{$user->id}")->assertNotFound();
    $this->assertGuest();
});

it('answers 404 in local to a request that does not come from this machine', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    bootDevRoutesIn('local');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get(route('dev.entrar'))->assertNotFound();
    postDemoSignIn($this, route('dev.entrar.usuario', $user), '10.0.0.8')->assertNotFound();
    $this->assertGuest();
});

it('ignores forwarded headers that claim the request is local', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    bootDevRoutesIn('local');

    $this->withHeader('X-Forwarded-For', '127.0.0.1');

    postDemoSignIn($this, route('dev.entrar.usuario', $user), '10.0.0.8')->assertNotFound();
    $this->assertGuest();
});

it('answers 404 if the routes were cached in local but the app now runs elsewhere', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    bootDevRoutesIn('local');
    app()['env'] = 'production';

    $this->get(route('dev.entrar'))->assertNotFound();
    postDemoSignIn($this, route('dev.entrar.usuario', $user))->assertNotFound();
    $this->assertGuest();
});

it('lists the active users with a role and their companies', function (string $address) {
    $analyst = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SDC, CompanyCode::SGX);
    $analyst->update(['name' => 'Ana Analista']);
    $advisor = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC);
    $advisor->update(['name' => 'Andrés Asesor']);
    User::factory()->create(['name' => 'Sin Rol']);
    User::factory()->inactive()->create(['name' => 'Inactivo Persona'])
        ->assignRole(SpatieRole::findOrCreate(Role::Admin->value, 'web'));

    bootDevRoutesIn('local');

    $this->withServerVariables(['REMOTE_ADDR' => $address])
        ->get(route('dev.entrar'))
        ->assertOk()
        ->assertSee('Entra como un usuario de prueba')
        ->assertSee('Ana Analista')
        ->assertSee($analyst->email)
        ->assertSee('Analista de cartera')
        ->assertSee('SGX, SDC')
        ->assertSee('Andrés Asesor')
        ->assertSee('Asesor comercial')
        ->assertSee(route('dev.entrar.usuario', $analyst), false)
        ->assertSee('name="_token"', false)
        ->assertDontSee('Sin Rol')
        ->assertDontSee('Inactivo Persona');

    $this->assertGuest();
})->with(['IPv4' => '127.0.0.1', 'IPv6' => '::1']);

it('signs in as the chosen user and sends them home', function (string $address) {
    $manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX, CompanyCode::SDC);

    bootDevRoutesIn('local');

    postDemoSignIn($this, route('dev.entrar.usuario', $manager), $address)
        ->assertRedirect(route('core.inicio'));

    $this->assertAuthenticatedAs($manager);
})->with(['IPv4' => '127.0.0.1', 'IPv6' => '::1']);

it('refuses a sign-in without the CSRF token of the session', function () {
    $manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX);

    bootDevRoutesIn('local');

    $this->post(route('dev.entrar.usuario', $manager))->assertStatus(419);
    $this->assertGuest();
});

it('does not sign in with a GET on the sign-in URL', function () {
    $manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX);

    bootDevRoutesIn('local');

    $status = $this->get(route('dev.entrar.usuario', $manager))->getStatusCode();

    expect($status)->toBeIn([404, 405]);
    $this->assertGuest();
});

it('refuses inactive users and users without a role', function () {
    $inactive = User::factory()->inactive()->create();
    $inactive->assignRole(SpatieRole::findOrCreate(Role::Admin->value, 'web'));
    $withoutRole = User::factory()->create();

    bootDevRoutesIn('local');

    postDemoSignIn($this, route('dev.entrar.usuario', $inactive))->assertNotFound();
    postDemoSignIn($this, route('dev.entrar.usuario', $withoutRole))->assertNotFound();
    postDemoSignIn($this, '/dev/entrar/999999')->assertNotFound();
    $this->assertGuest();
});

it('keeps the sign-in a POST inside the web group', function () {
    bootDevRoutesIn('local');

    $route = Route::getRoutes()->getByName('dev.entrar.usuario');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain('web');
});
