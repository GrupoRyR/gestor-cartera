<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\CoreUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
});

/**
 * Main screens of other modules, registered by name as their providers would.
 */
function registerHomeScreens(string ...$names): void
{
    Route::middleware(['web', 'auth'])->group(function () use ($names) {
        foreach ($names as $name) {
            Route::get("/_test/{$name}", fn () => $name)->name("test.{$name}");
        }
    });

    Route::getRoutes()->refreshNameLookups();
}

it('is served at GET / as core.inicio', function () {
    expect(route('core.inicio', absolute: false))->toBe('/');
});

it('sends guests to the sign-in page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('sends a user without a role to the no-access screen', function () {
    registerHomeScreens('cartera');
    app(NavRegistry::class)->add(new NavItem(key: 'test.cartera', label: 'Tablero', route: 'test.cartera'));

    $this->actingAs(CoreUsers::create(null, CompanyCode::SGX))
        ->get('/')
        ->assertRedirect(route('core.sin-acceso'));
});

it('opens the first menu entry the user may see', function () {
    registerHomeScreens('cartera', 'cobro');
    app(NavRegistry::class)->add(
        new NavItem(key: 'test.cobro', label: 'Cobro', route: 'test.cobro', order: 20),
        new NavItem(key: 'test.cartera', label: 'Tablero', route: 'test.cartera', order: 10),
    );

    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX))
        ->get('/')
        ->assertRedirect(route('test.cartera'));
});

it('sends each role to the first screen its permissions allow', function (Role $role, string $home) {
    registerHomeScreens('gerencia', 'cartera');
    SpatieRole::findByName(Role::PortfolioManager->value, 'web')->givePermissionTo(Permission::findOrCreate('test.gerencia', 'web'));
    app(NavRegistry::class)->add(
        new NavItem(key: 'test.gerencia', label: 'Tablero gerencial', route: 'test.gerencia', permission: 'test.gerencia', order: 10),
        new NavItem(key: 'test.cartera', label: 'Tablero', route: 'test.cartera', permission: 'core.access', order: 20),
    );

    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX))
        ->get('/')
        ->assertRedirect(route($home));
})->with([
    'gerente de cartera' => [Role::PortfolioManager, 'test.gerencia'],
    'asesor' => [Role::SalesAdvisor, 'test.cartera'],
]);

it('skips entries whose route is missing or that point back home', function () {
    registerHomeScreens('cobro');
    app(NavRegistry::class)->add(
        new NavItem(key: 'test.inicio', label: 'Inicio', route: 'core.inicio', order: 1),
        new NavItem(key: 'test.sin-ruta', label: 'Sin ruta', route: 'test.sin-ruta', order: 2),
        new NavItem(key: 'test.cobro', label: 'Cobro', route: 'test.cobro', order: 3),
    );

    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX))
        ->get('/')
        ->assertRedirect(route('test.cobro'));
});

it('shows a landing page while no screen is enabled for the user', function () {
    app(NavRegistry::class)->add(
        new NavItem(key: 'test.inicio', label: 'Inicio', route: 'core.inicio', order: 1),
        new NavItem(key: 'test.sin-ruta', label: 'Sin ruta', route: 'test.sin-ruta', order: 2),
    );
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $user->update(['name' => 'Ana María Gómez']);

    $html = Html::parse($this->actingAs($user)->get('/')->assertOk()->assertViewIs('core::inicio')->getContent() ?: '');

    expect($html->text('//h1'))->toBe('Hola, Ana')
        ->and($html->text('//main'))->toContain('Tus pantallas aparecerán aquí a medida que se habiliten los módulos.')
        ->and($html->text('//title'))->toBe('Inicio · '.config('app.name'))
        ->and($html->text('//header//*[@data-part="user"]'))->toContain('Ana María Gómez');
});
