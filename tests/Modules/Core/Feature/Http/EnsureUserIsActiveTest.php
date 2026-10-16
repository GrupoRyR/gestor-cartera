<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Modules\Core\Support\CoreUsers;

const DEACTIVATED_MESSAGE = 'Tu cuenta está desactivada. Habla con TI si crees que es un error.';

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);

    // A web page of no module and without the auth middleware: only the web
    // group stands between the user and the page.
    Route::middleware('web')->get('/_test/abierta', fn () => 'abierta');
});

function deactivatedUser(): User
{
    return tap(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX))->update(['is_active' => false]);
}

it('signs an inactive user out of a Core page', function () {
    $this->actingAs(deactivatedUser())
        ->get('/')
        ->assertRedirect('/login')
        ->assertSessionHas('error', DEACTIVATED_MESSAGE);

    $this->assertGuest();
});

it('signs an inactive user out of any other web page', function () {
    $this->actingAs(deactivatedUser())
        ->get('/_test/abierta')
        ->assertRedirect('/login')
        ->assertSessionHas('error', DEACTIVATED_MESSAGE);

    $this->assertGuest();
});

it('answers 401 in JSON when an inactive user expects JSON', function () {
    $this->actingAs(deactivatedUser())
        ->getJson('/_test/abierta')
        ->assertUnauthorized()
        ->assertExactJson(['message' => DEACTIVATED_MESSAGE]);

    $this->assertGuest();
});

it('lets guests and active users through', function () {
    $this->get('/_test/abierta')->assertOk()->assertSee('abierta');

    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    $this->actingAs($user)->get('/_test/abierta')->assertOk()->assertSee('abierta');
    $this->assertAuthenticatedAs($user);
});

it('treats a user loaded without is_active as active instead of failing', function () {
    $id = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX)->getKey();

    $this->actingAs(User::query()->select(['id', 'name', 'email'])->findOrFail($id))
        ->get('/_test/abierta')
        ->assertOk();
});

it('treats a soft-deleted user as a guest', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
    $user->delete();

    $this->withSession([Auth::guard('web')->getName() => $user->getKey()])
        ->get('/')
        ->assertRedirect('/login')
        ->assertSessionMissing('error');

    $this->assertGuest();
});
