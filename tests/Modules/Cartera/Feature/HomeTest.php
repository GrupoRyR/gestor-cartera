<?php

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavRegistry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cartera\Support\CarteraUsers;

beforeEach(function () {
    ContractFakes::install($this->app);
});

it('sends the portfolio manager and the analyst straight to the dashboard from /', function (Role $role) {
    $this->actingAs(CarteraUsers::create($role))->get('/')->assertRedirect(route('cartera.tablero'));
})->with([Role::PortfolioManager, Role::PortfolioAnalyst]);

it('puts the dashboard before every other menu entry they may open', function (Role $role) {
    $user = CarteraUsers::create($role);
    $labels = array_map(fn ($item): string => $item->label, app(NavRegistry::class)->visibleTo($user));

    expect($labels[0])->toBe('Tablero');

    // The manager also opens Parámetros (Core), which must come after.
    if ($role === Role::PortfolioManager) {
        expect($labels)->toContain('Parámetros')
            ->and(array_search('Tablero', $labels, true))->toBeLessThan(array_search('Parámetros', $labels, true));
    }
})->with([Role::PortfolioManager, Role::PortfolioAnalyst]);
