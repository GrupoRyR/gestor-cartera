<?php

use App\Models\User;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/tablero', fn () => 'ok')->name('cartera.tablero');
    Route::get('/clientes', fn () => 'ok')->name('cartera.clientes');
    Route::get('/lotes', fn () => 'ok')->name('cobro.lotes');
    Route::getRoutes()->refreshNameLookups();

    Gate::define('cobro.send', fn (User $user): bool => $user->email === 'analista@example.com');

    $this->nav = new NavRegistry;
    $this->user = User::factory()->make(['email' => 'asesor@example.com']);
});

/**
 * Builds a request already matched to one of the routes above.
 */
function matchedNavRequest(string $uri): Request
{
    $request = Request::create($uri);
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('rejects two items with the same key and keeps the registry unchanged', function () {
    $this->nav->add(new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero'));

    expect(fn () => $this->nav->add(
        new NavItem(key: 'cartera.clientes', label: 'Clientes', route: 'cartera.clientes'),
        new NavItem(key: 'cartera.tablero', label: 'Otro tablero', route: 'cartera.tablero'),
    ))->toThrow(InvalidArgumentException::class, 'cartera.tablero');

    expect($this->nav->has('cartera.tablero'))->toBeTrue()
        ->and($this->nav->has('cartera.clientes'))->toBeFalse();
});

it('sorts items by order and then by label', function () {
    $this->nav->add(
        new NavItem(key: 'b', label: 'Clientes', route: 'cartera.clientes', order: 20),
        new NavItem(key: 'c', label: 'Lotes', route: 'cobro.lotes', order: 10),
        new NavItem(key: 'a', label: 'Bandeja', route: 'cartera.tablero', order: 20),
    );

    expect(array_map(fn (NavItem $item) => $item->key, $this->nav->all()))->toBe(['c', 'a', 'b']);
});

it('shows nothing to guests', function () {
    $this->nav->add(new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero'));

    expect($this->nav->visibleTo(null))->toBe([])
        ->and($this->nav->groupedFor(null))->toBe([])
        ->and($this->nav->mobileFor(null))->toBe([]);
});

it('hides items whose permission the user does not have', function () {
    $this->nav->add(
        new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero'),
        new NavItem(key: 'cobro.lotes', label: 'Lotes de cobro', route: 'cobro.lotes', permission: 'cobro.send'),
    );

    $analista = User::factory()->make(['email' => 'analista@example.com']);

    expect(array_map(fn (NavItem $item) => $item->key, $this->nav->visibleTo($this->user)))->toBe(['cartera.tablero'])
        ->and(array_map(fn (NavItem $item) => $item->key, $this->nav->visibleTo($analista)))->toBe(['cobro.lotes', 'cartera.tablero']);
});

it('skips items whose route does not exist', function () {
    $this->nav->add(
        new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero'),
        new NavItem(key: 'bancos.movimientos', label: 'Movimientos', route: 'bancos.movimientos'),
    );

    expect(array_map(fn (NavItem $item) => $item->key, $this->nav->visibleTo($this->user)))->toBe(['cartera.tablero']);
});

it('groups visible items in the order of their first item', function () {
    $this->nav->add(
        new NavItem(key: 'cobro.lotes', label: 'Lotes', route: 'cobro.lotes', group: 'Cobro', order: 10),
        new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero', group: 'Cartera', order: 20),
        new NavItem(key: 'cartera.clientes', label: 'Clientes', route: 'cartera.clientes', group: 'Cobro', order: 30),
    );

    $groups = $this->nav->groupedFor($this->user);

    expect(array_keys($groups))->toBe(['Cobro', 'Cartera'])
        ->and(array_map(fn (NavItem $item) => $item->key, $groups['Cobro']))->toBe(['cobro.lotes', 'cartera.clientes']);
});

it('lists only the items meant for the phone bottom bar', function () {
    $this->nav->add(
        new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero', mobile: true),
        new NavItem(key: 'cartera.clientes', label: 'Clientes', route: 'cartera.clientes'),
    );

    expect(array_map(fn (NavItem $item) => $item->key, $this->nav->mobileFor($this->user)))->toBe(['cartera.tablero']);
});

it('marks an item active on its own route or on its active pattern', function () {
    $tablero = new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero');
    $cartera = new NavItem(key: 'cartera', label: 'Cartera', route: 'cartera.tablero', activePattern: 'cartera.*');

    $request = matchedNavRequest('/clientes');

    expect($tablero->isActive($request))->toBeFalse()
        ->and($cartera->isActive($request))->toBeTrue()
        ->and($tablero->isActive(matchedNavRequest('/tablero')))->toBeTrue();
});

it('uses the current request when none is given', function () {
    $this->app->instance('request', matchedNavRequest('/tablero'));

    $item = new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero');

    expect($item->isActive())->toBeTrue();
});

it('builds the item URL from its route name', function () {
    $item = new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero');

    expect($item->url())->toBe(route('cartera.tablero'));
});

it('computes the badge only when it is asked for', function () {
    $calls = 0;
    $item = new NavItem(
        key: 'cobro.respuestas',
        label: 'Respuestas de clientes',
        route: 'cobro.lotes',
        badge: function () use (&$calls): ?int {
            $calls++;

            return 3;
        },
    );

    expect($calls)->toBe(0)
        ->and($item->badgeCount())->toBe(3)
        ->and($calls)->toBe(1)
        ->and((new NavItem(key: 'x', label: 'Sin contador', route: 'cobro.lotes'))->badgeCount())->toBeNull();
});
