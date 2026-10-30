<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Feature\Design\Support\Html;

/**
 * Pages rendered with <x-layouts.app> through real web routes, so the
 * navigation can tell which page is active.
 */
function registerLayoutPages(): void
{
    // An empty menu registry: the real entries of the modules would add links and permission checks to every page.
    app()->instance(NavRegistry::class, new NavRegistry);

    $page = fn (string $title) => fn () => Blade::render(
        '<x-layouts.app :title="$title"><p>Contenido de {{ $title }}</p></x-layouts.app>',
        ['title' => $title],
    );

    Route::middleware('web')->group(function () use ($page) {
        Route::get('/cartera/tablero', $page('Tablero'))->name('cartera.tablero');
        Route::get('/cartera/clientes', $page('Clientes'))->name('cartera.clientes');
        Route::get('/cobro/lotes', $page('Lotes'))->name('cobro.lotes');
    });

    Route::getRoutes()->refreshNameLookups();
}

/**
 * Registers a route by name only, as Core will in phase 0b.
 */
function registerNamedPost(string $uri, string $name): void
{
    Route::post($uri, fn () => null)->name($name);
    Route::getRoutes()->refreshNameLookups();
}

/**
 * Drops the named routes, as if Core had not registered them yet. Names
 * that are not registered are ignored.
 */
function withoutRoutes(string ...$names): void
{
    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array($route->getName(), $names, true)) {
            $routes->add($route);
        }
    }

    app('router')->setRoutes($routes);
}

/**
 * Asserts the light and dark --brand-* variables of $palette.
 */
function assertPalette(string $html, BrandPalette $palette): void
{
    [$light, $dark] = [Str::before($html, '@media (prefers-color-scheme: dark)'), Str::after($html, '@media (prefers-color-scheme: dark)')];

    foreach ($palette->toCssVariables() as $variable => $color) {
        expect($light)->toContain("{$variable}: {$color};");
    }

    foreach ($palette->toCssVariables(dark: true) as $variable => $color) {
        expect($dark)->toContain("{$variable}: {$color};");
    }
}

describe('brand icons', function () {
    it('links the favicon and home-screen icon of the active company', function (?CompanyCode $company, string $brand) {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany($company));
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->one('//html')->getAttribute('data-brand'))->toBe($brand)
            ->and($html->one('//link[@rel="icon"]')->getAttribute('href'))->toBe(asset("icons/{$brand}/favicon.svg"))
            ->and($html->one('//link[@rel="apple-touch-icon"]')->getAttribute('href'))->toBe(asset("icons/{$brand}/apple-touch-icon.png"))
            ->and(public_path("icons/{$brand}/favicon.svg"))->toBeFile()
            ->and(public_path("icons/{$brand}/apple-touch-icon.png"))->toBeFile();
    })->with([
        'Segurex' => [CompanyCode::SGX, 'sgx'],
        'Derivados' => [CompanyCode::SDC, 'sdc'],
        'group view' => [null, 'neutral'],
    ]);

    it('gives guests the neutral icons', function () {
        $html = Html::parse((string) $this->blade('<x-layouts.guest>Hola</x-layouts.guest>'));

        expect($html->one('//html')->getAttribute('data-brand'))->toBe('neutral')
            ->and($html->one('//link[@rel="icon"]')->getAttribute('href'))->toBe(asset('icons/neutral/favicon.svg'));
    });
});

describe('<x-layouts.guest>', function () {
    it('renders a Spanish page with the neutral palette and no navigation', function () {
        $rendered = (string) $this->blade('<x-layouts.guest title="Ingresar"><p>Ingresa con tu cuenta de Microsoft.</p></x-layouts.guest>');
        $html = Html::parse($rendered);

        expect($html->one('//html')->getAttribute('lang'))->toBe('es')
            ->and($html->text('//title'))->toBe('Ingresar · '.config('app.name'))
            ->and($html->one('//meta[@name="viewport"]')->getAttribute('content'))->toContain('width=device-width')
            ->and($html->count('//meta[@name="csrf-token"]'))->toBe(1)
            ->and($html->count('//nav'))->toBe(0)
            ->and($html->text('//main[@id="contenido"]'))->toBe('Ingresa con tu cuenta de Microsoft.')
            ->and($html->text('//a[@href="#contenido"]'))->toBe('Saltar al contenido');

        assertPalette($rendered, BrandPalette::neutral());
    });

    it('shows the app name and no company logo: brand v2 never shows both companies together', function () {
        $html = Html::parse((string) $this->blade('<x-layouts.guest>Hola</x-layouts.guest>'));

        expect($html->text('//header//*[@data-part="brand"]'))->toBe(config('app.name'))
            ->and($html->count('//img'))->toBe(0)
            ->and($html->one('//meta[@name="theme-color"][@media="(prefers-color-scheme: light)"]')->getAttribute('content'))->toBe(BrandPalette::neutral()->head);
    });

    it('keeps the neutral palette for a signed-in user', function () {
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        assertPalette((string) $this->blade('<x-layouts.guest>Hola</x-layouts.guest>'), BrandPalette::neutral());
    });
});

describe('<x-layouts.app>', function () {
    it('gives guests the neutral palette, the skip link and the content', function () {
        ContractFakes::install($this->app);

        $rendered = (string) $this->blade('<x-layouts.app><p>Contenido</p></x-layouts.app>');
        $html = Html::parse($rendered);

        expect($html->one('//html')->getAttribute('lang'))->toBe('es')
            ->and($html->text('//title'))->toBe(config('app.name'))
            ->and($html->text('//a[@href="#contenido"]'))->toBe('Saltar al contenido')
            ->and($html->text('//main[@id="contenido"]'))->toBe('Contenido')
            ->and($html->count('//nav'))->toBe(0);

        assertPalette($rendered, BrandPalette::neutral());
    });

    it('uses the palette of the active company of a signed-in user', function () {
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make(['name' => 'Ana Gómez']));

        $rendered = (string) $this->blade('<x-layouts.app title="Tablero">Contenido</x-layouts.app>');

        assertPalette($rendered, BrandPalette::official(CompanyCode::SGX));
        expect(Html::parse($rendered)->text('//header'))->toContain('Ana Gómez');
    });

    it('falls back to the neutral palette while CurrentCompany is not bound', function () {
        $this->actingAs(User::factory()->make());

        assertPalette((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'), BrandPalette::neutral());
    });

    it('shows the navigation the user may open and marks the current page', function () {
        registerLayoutPages();
        app(NavRegistry::class)->add(
            new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero', icon: 'home', group: 'Cartera', order: 10, mobile: true, badge: fn (): int => 3),
            new NavItem(key: 'cartera.clientes', label: 'Clientes', route: 'cartera.clientes', group: 'Cartera', order: 20),
            new NavItem(key: 'cobro.lotes', label: 'Lotes', route: 'cobro.lotes', group: 'Cobro', order: 30),
        );

        $html = Html::parse($this->actingAs(User::factory()->make())->get('/cartera/clientes')->assertOk()->getContent() ?: '');

        $sidebar = '//nav[@aria-label="Navegación principal"]';
        $bottomBar = '//nav[@aria-label="Accesos rápidos"]';

        expect(array_map(fn (DOMElement $h): string => Html::clean($h->textContent), $html->all("{$sidebar}//h2")))->toBe(['Cartera', 'Cobro'])
            ->and(array_map(fn (DOMElement $a): string => Html::clean($a->textContent), $html->all("{$sidebar}//a")))->toBe(['Tablero 3', 'Clientes', 'Lotes'])
            ->and($html->text("{$sidebar}//a[@aria-current='page']"))->toBe('Clientes')
            ->and($html->one("{$sidebar}//a[contains(., 'Tablero')]")->getAttribute('href'))->toBe(route('cartera.tablero'))
            ->and(array_map(fn (DOMElement $a): string => Html::clean($a->textContent), $html->all("{$bottomBar}//a")))->toBe(['Tablero 3'])
            ->and($html->count("{$bottomBar}//a[@aria-current]"))->toBe(0)
            ->and($html->text('//main'))->toBe('Contenido de Clientes')
            ->and($html->text('//title'))->toBe('Clientes · '.config('app.name'));
    });

    it('renders the CSRF token that window.apiFetch() sends', function () {
        registerLayoutPages();

        $html = Html::parse($this->actingAs(User::factory()->make())->get('/cartera/tablero')->assertOk()->getContent() ?: '');

        expect($html->count('//meta[@name="csrf-token"]'))->toBe(1)
            ->and($html->one('//meta[@name="csrf-token"]')->getAttribute('content'))
            ->not->toBeEmpty()
            ->toBe(session()->token());
    });

    it('opens the main navigation with a labelled menu button on small screens', function () {
        registerLayoutPages();
        app(NavRegistry::class)->add(new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero'));

        $html = Html::parse($this->actingAs(User::factory()->make())->get('/cartera/tablero')->assertOk()->getContent() ?: '');

        $nav = $html->one('//nav[@aria-label="Navegación principal"]');
        $button = $html->one('//header//button[@aria-controls]');

        expect($button->getAttribute('aria-controls'))->toBe($nav->getAttribute('id'))
            ->and($button->getAttribute('aria-expanded'))->toBe('false')
            ->and(Html::clean($button->textContent))->toBe('Abrir el menú')
            ->and(Html::clean($html->one('//nav[@aria-label="Navegación principal"]//button')->textContent))->toBe('Cerrar el menú');
    });

    it('shows the account of the signed-in user in its menu', function () {
        $this->actingAs(User::factory()->make(['name' => 'Ana Gómez', 'email' => 'ana.gomez@segurex.example']));

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));
        $account = $html->one('//header//details[@data-part="account"]');

        expect(Html::clean($account->textContent))->toContain('AG')
            ->toContain('Tu cuenta')
            ->toContain('ana.gomez@segurex.example');
    });

    it('checks each navigation permission once per page', function () {
        registerLayoutPages();
        $checks = 0;
        Gate::define('cartera.view', function () use (&$checks): bool {
            $checks++;

            return true;
        });
        app(NavRegistry::class)->add(
            new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero', permission: 'cartera.view', mobile: true),
        );

        $html = Html::parse($this->actingAs(User::factory()->make())->get('/cartera/clientes')->assertOk()->getContent() ?: '');

        expect($html->count('//nav[@aria-label="Navegación principal"]//a'))->toBe(1)
            ->and($html->count('//nav[@aria-label="Accesos rápidos"]//a'))->toBe(1)
            ->and($checks)->toBe(1);
    });

    it('shows no navigation to guests', function () {
        registerLayoutPages();
        app(NavRegistry::class)->add(new NavItem(key: 'cartera.tablero', label: 'Tablero', route: 'cartera.tablero', mobile: true));

        expect(Html::parse($this->get('/cartera/tablero')->getContent() ?: '')->count('//nav'))->toBe(0);
    });

    it('shows flash messages as alerts', function () {
        registerLayoutPages();

        $html = Html::parse($this->actingAs(User::factory()->make())
            ->withSession(['status' => 'Guardamos el compromiso.', 'error' => 'No pudimos enviar el correo.'])
            ->get('/cartera/tablero')
            ->getContent() ?: '');

        expect($html->text('//main//*[@role="status"]'))->toBe('Guardamos el compromiso.')
            ->and($html->text('//main//*[@role="alert"]'))->toBe('No pudimos enviar el correo.');
    });

    it('hides the company selector while Core has no route for it', function () {
        withoutRoutes('core.empresa.cambiar', 'core.logout');
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->count('//button[@name="empresa"]'))->toBe(0)
            ->and($html->count('//form'))->toBe(0);
    });

    it('lets a user of several companies switch between them and the group', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        $form = $html->one('//header//form[.//button[@name="empresa"]]');
        $buttons = $html->all('//header//*[@role="group"][@aria-label="Empresa"]/button');

        expect(strtoupper($form->getAttribute('method')))->toBe('POST')
            ->and($form->getAttribute('action'))->toBe(route('core.empresa.cambiar'))
            ->and($html->count('//form[.//button[@name="empresa"]]//input[@name="_token"]'))->toBe(1)
            ->and(array_map(fn (DOMElement $b): string => $b->getAttribute('type'), $buttons))->toBe(['submit', 'submit', 'submit'])
            ->and(array_map(fn (DOMElement $b): string => $b->getAttribute('name'), $buttons))->toBe(['empresa', 'empresa', 'empresa'])
            ->and(array_map(fn (DOMElement $b): string => $b->getAttribute('value'), $buttons))->toBe(['SGX', 'SDC', 'grupo'])
            ->and(array_map(fn (DOMElement $b): string => $b->getAttribute('aria-pressed'), $buttons))->toBe(['true', 'false', 'false']);
    });

    it('shows short company labels that start the accessible name of each button', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));
        $buttons = $html->all('//button[@name="empresa"]');
        $visible = array_map(fn (DOMElement $b): string => Html::clean($b->textContent), $buttons);
        $names = array_map(fn (DOMElement $b): string => $b->getAttribute('aria-label'), $buttons);

        // The full name is the accessible name on every screen size, and it starts with the visible label.
        expect($visible)->toBe(['Segurex', 'SDC', 'Grupo'])
            ->and($names)->toBe(['Segurex Latam', 'SDC (Soluciones y Derivados)', 'Grupo RYR'])
            ->and($html->count('//button[@name="empresa"]//*[@aria-hidden="true"]'))->toBe(0);
    });

    it('presses the group view when it is active', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        $this->app->instance(CurrentCompany::class, FakeCurrentCompany::group());
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->one('//button[@name="empresa"][@aria-pressed="true"]')->getAttribute('value'))->toBe('grupo');
    });

    it('shows only the logo to users of a single company', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SDC, [CompanyCode::SDC]));
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->count('//button[@name="empresa"]'))->toBe(0)
            ->and($html->count('//*[@data-part="company-selector"]'))->toBe(0)
            ->and($html->one('//header//*[@data-part="brand"]//img')->getAttribute('alt'))->toBe('Soluciones y Derivados');
    });

    it('shows the logo of the active company in the header', function (CompanyCode $company, string $file, bool $plate) {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany($company));
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));
        $logo = $html->one('//header//a[@data-part="brand"]//img');

        expect($logo->getAttribute('src'))->toBe(asset("icons/marcas/{$file}"))
            ->and($logo->getAttribute('alt'))->toBe($company->label())
            ->and((int) $logo->getAttribute('width'))->toBeGreaterThan(0)
            ->and((int) $logo->getAttribute('height'))->toBeGreaterThan(0)
            ->and($html->count('//header//*[@data-part="logo-plate"]/img'))->toBe($plate ? 1 : 0)
            ->and(public_path("icons/marcas/{$file}"))->toBeFile();
    })->with([
        'Segurex, white on the header' => [CompanyCode::SGX, 'segurex-blanco.png', false],
        'Derivados, on a white plate' => [CompanyCode::SDC, 'sdc.png', true],
    ]);

    it('shows no company logo in the group view', function () {
        $this->app->instance(CurrentCompany::class, FakeCurrentCompany::group());
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->count('//header//img'))->toBe(0)
            ->and($html->text('//header//a[@data-part="brand"]'))->toContain(config('app.name'));
    });

    it('paints the browser bar with the header color of the active company', function (?CompanyCode $company, BrandPalette $palette) {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany($company));
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->one('//meta[@name="theme-color"][@media="(prefers-color-scheme: light)"]')->getAttribute('content'))->toBe($palette->head)
            ->and($html->one('//meta[@name="theme-color"][@media="(prefers-color-scheme: dark)"]')->getAttribute('content'))->toBe($palette->darkHead);
    })->with([
        'Segurex' => [CompanyCode::SGX, BrandPalette::official(CompanyCode::SGX)],
        'Derivados' => [CompanyCode::SDC, BrandPalette::official(CompanyCode::SDC)],
        'group view' => [null, BrandPalette::neutral()],
    ]);

    it('offers to sign out once Core registers the route', function () {
        withoutRoutes('core.logout');
        $this->actingAs(User::factory()->make());

        $before = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        registerNamedPost('/salir', 'core.logout');

        $after = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));
        $form = $after->one('//form[.//button[normalize-space(.)="Salir"]]');

        expect($before->count('//button'))->toBe(0)
            ->and(strtoupper($form->getAttribute('method')))->toBe('POST')
            ->and($form->getAttribute('action'))->toBe(route('core.logout'))
            ->and($after->count('//form//input[@name="_token"]'))->toBe(1);
    });
});
