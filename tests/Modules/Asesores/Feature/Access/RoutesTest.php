<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * The web routes of the module.
 *
 * @return array<string, LaravelRoute>
 */
function asesoresRoutes(): array
{
    return array_filter(
        Route::getRoutes()->getRoutesByName(),
        fn (LaravelRoute $route, string $name): bool => str_starts_with($name, 'asesores.'),
        ARRAY_FILTER_USE_BOTH,
    );
}

it('has routes to check', function () {
    expect(asesoresRoutes())->not->toBeEmpty();
});

it('asks for a signed-in user on every route', function () {
    foreach (asesoresRoutes() as $name => $route) {
        $this->assertContains('auth', $route->gatherMiddleware(), "{$name} is open to guests.");
    }
});

it('asks for a permission on every route', function () {
    foreach (asesoresRoutes() as $name => $route) {
        $permissions = array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:asesores.'));

        $this->assertNotEmpty($permissions, "{$name} has no asesores permission.");
    }
});

it('asks for the write permission on every route that changes something', function () {
    foreach (asesoresRoutes() as $name => $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $this->assertContains('can:asesores.manage', $route->gatherMiddleware(), "{$name} writes without asesores.manage.");
    }
});

it('serves nothing under the API prefix: the module has no API', function () {
    $api = array_filter(Route::getRoutes()->getRoutesByName(), fn (LaravelRoute $route, string $name): bool => str_starts_with($name, 'asesores.api.'), ARRAY_FILTER_USE_BOTH);

    expect($api)->toBe([]);
});
