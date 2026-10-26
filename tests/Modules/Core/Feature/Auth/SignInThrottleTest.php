<?php

use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Laravel\Socialite\Two\InvalidStateException;
use Tests\Modules\Core\Support\FakeIdentityProvider;

/*
| The sign-in routes are open to guests, so each one takes at most 20
| requests a minute from one IP (audit S-09). The limit is per route: a
| normal sign-in uses three of them, and behind a proxy everyone may share
| one IP.
*/

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    config(['services.microsoft.tenant' => FakeIdentityProvider::TENANT]);
    FakeIdentityProvider::install($this->app)->fails(new InvalidStateException);
});

it('answers 429 to the 21st request of a minute', function (string $uri, int $status) {
    foreach (range(1, 20) as $attempt) {
        $this->get($uri)->assertStatus($status);
    }

    $this->get($uri)->assertTooManyRequests();
})->with([
    'sign-in page' => ['/login', 200],
    'redirect to Microsoft' => ['/auth/microsoft', 302],
    'callback' => ['/auth/microsoft/callback?code=x&state=y', 302],
]);

it('counts a route once, whatever its query string', function () {
    foreach (range(1, 20) as $attempt) {
        $this->get("/auth/microsoft/callback?code=x&state={$attempt}");
    }

    $this->get('/auth/microsoft/callback?code=x&state=otro')->assertTooManyRequests();
});

it('counts each route on its own', function () {
    foreach (range(1, 21) as $attempt) {
        $this->get('/login');
    }

    $this->get('/auth/microsoft/callback?code=x&state=y')->assertRedirect('/login');
});

it('counts each IP on its own', function () {
    foreach (range(1, 21) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/login');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/login')->assertOk();
});
