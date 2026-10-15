<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
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
 * Asserts the light and dark --brand-* variables of $palette.
 */
function assertPalette(string $html, BrandPalette $palette): void
{
    [$light, $dark] = [Str::before($html, 'prefers-color-scheme: dark'), Str::after($html, 'prefers-color-scheme: dark')];

    foreach ($palette->toCssVariables() as $variable => $color) {
        expect($light)->toContain("{$variable}: {$color};");
    }

    foreach ($palette->toCssVariables(dark: true) as $variable => $color) {
        expect($dark)->toContain("{$variable}: {$color};");
    }
}

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
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->count('//select[@name="empresa"]'))->toBe(0)
            ->and($html->count('//form'))->toBe(0);
    });

    it('lets a user of several companies switch between them and the group', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        $form = $html->one('//form[.//select[@name="empresa"]]');
        $select = $html->labelledControl('Empresa');

        expect(strtoupper($form->getAttribute('method')))->toBe('POST')
            ->and($form->getAttribute('action'))->toBe(route('core.empresa.cambiar'))
            ->and($html->count('//form//input[@name="_token"]'))->toBe(1)
            ->and($select->getAttribute('name'))->toBe('empresa')
            ->and(array_map(fn (DOMElement $o): string => $o->getAttribute('value'), $html->all('//select[@name="empresa"]/option')))->toBe(['SGX', 'SDC', 'grupo'])
            ->and($html->one('//select[@name="empresa"]/option[@selected]')->getAttribute('value'))->toBe('SGX')
            ->and($html->text('//form//button[@type="submit"]'))->toBe('Cambiar');
    });

    it('preselects the group view', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        $this->app->instance(CurrentCompany::class, FakeCurrentCompany::group());
        $this->actingAs(User::factory()->make());

        $html = Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'));

        expect($html->one('//select[@name="empresa"]/option[@selected]')->getAttribute('value'))->toBe('grupo');
    });

    it('hides the company selector from users of a single company', function () {
        registerNamedPost('/empresa', 'core.empresa.cambiar');
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SDC, [CompanyCode::SDC]));
        $this->actingAs(User::factory()->make());

        expect(Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'))->count('//select[@name="empresa"]'))->toBe(0);
    });

    it('offers to sign out once Core registers the route', function () {
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
