<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
});

dataset('team routes', [
    'list' => ['get', 'core.equipo.index'],
    'new form' => ['get', 'core.equipo.create'],
    'create' => ['post', 'core.equipo.store'],
    'edit form' => ['get', 'core.equipo.edit'],
    'update' => ['put', 'core.equipo.update'],
    'delete' => ['delete', 'core.equipo.destroy'],
    'restore' => ['post', 'core.equipo.restore'],
]);

/**
 * The URL of an Equipo route, about $target when the route takes a user.
 */
function teamRouteUrl(string $name, User $target): string
{
    $takesUser = str_contains(Route::getRoutes()->getByName($name)?->uri() ?? '', '{user}');

    return $takesUser ? route($name, $target) : route($name);
}

it('serves the Equipo screen under /equipo', function () {
    expect(route('core.equipo.index', absolute: false))->toBe('/equipo')
        ->and(route('core.equipo.create', absolute: false))->toBe('/equipo/nuevo')
        ->and(route('core.equipo.edit', 7, absolute: false))->toBe('/equipo/7/editar')
        ->and(route('core.equipo.update', 7, absolute: false))->toBe('/equipo/7')
        ->and(route('core.equipo.restore', 7, absolute: false))->toBe('/equipo/7/restaurar');
});

it('sends guests to the sign-in page', function (string $method, string $name) {
    $target = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    $this->{$method}(teamRouteUrl($name, $target))->assertRedirect('/login');
})->with('team routes');

it('forbids every role but admin', function (?Role $role, string $method, string $name) {
    $target = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);

    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC))
        ->{$method}(teamRouteUrl($name, $target))
        ->assertForbidden();

    expect(User::withTrashed()->findOrFail($target->id)->trashed())->toBeFalse();
})->with([
    'gerente de cartera' => [Role::PortfolioManager],
    'analista de cartera' => [Role::PortfolioAnalyst],
    'asesor' => [Role::SalesAdvisor],
    'gerente comercial' => [Role::SalesManager],
    'tesorería' => [Role::Treasury],
    'sin rol' => [null],
])->with('team routes');

it('shows Equipo in the admin menu and opens it from home', function () {
    $admin = TeamUsers::admin();

    $this->actingAs($admin)->get('/')->assertRedirect(route('core.equipo.index'));

    $html = TeamUsers::html($this->actingAs($admin)->get('/equipo')->assertOk());
    $link = $html->one('//nav[@id="menu-principal"]//a[@aria-current="page"]');

    expect(Html::clean($link->textContent))->toBe('Equipo')
        ->and($link->getAttribute('href'))->toBe(route('core.equipo.index'))
        ->and($html->text('//nav[@id="menu-principal"]//h2'))->toBe('Administración')
        ->and($html->text('//h1'))->toBe('Equipo')
        ->and($html->text('//title'))->toBe('Equipo · '.config('app.name'));
});

it('keeps Equipo out of the menu of the other roles', function () {
    $visibleKeys = fn (User $user): array => array_map(
        fn (NavItem $item): string => $item->key,
        app(NavRegistry::class)->visibleTo($user),
    );

    expect($visibleKeys(TeamUsers::admin()))->toContain('core.equipo')
        ->and($visibleKeys(CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX)))->not->toContain('core.equipo')
        ->and($visibleKeys(CoreUsers::create(null)))->not->toContain('core.equipo');
});
