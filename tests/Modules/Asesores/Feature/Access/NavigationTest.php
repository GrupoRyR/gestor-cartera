<?php

use App\Models\User;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
});

/**
 * The keys of the Asesores menu entries a user sees, in menu order.
 *
 * @return list<string>
 */
function asesoresMenuOf(User $user): array
{
    $keys = array_map(
        fn ($item): string => $item->key,
        app(NavRegistry::class)->visibleTo($user),
    );

    return array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'asesores.')));
}

it('gives advisors and sales managers the viewer screens', function (string $person) {
    expect(asesoresMenuOf($this->world->{$person}))->toBe(['asesores.resumen', 'asesores.clientes', 'asesores.agenda', 'asesores.historico']);
})->with(['an advisor' => ['ana'], 'a sales manager' => ['gloria']]);

it('keeps the viewer out of the menu of the other roles', function () {
    expect(asesoresMenuOf($this->world->mariaPaula))->toBe([]);
});

it('is the home of an advisor, where Core sends them after signing in', function () {
    $this->actingAs($this->world->ana)->get('/')->assertRedirect(route('asesores.inicio'));
});

it('puts the dashboard first and marks the quick access of the phone', function () {
    $items = app(NavRegistry::class)->mobileFor($this->world->ana);

    expect(array_map(fn ($item): string => $item->key, $items))->toBe(['asesores.resumen', 'asesores.clientes', 'asesores.agenda']);
});
