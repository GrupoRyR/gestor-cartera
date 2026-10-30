<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Companies\SessionCurrentCompany;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\CoreUsers;

it('is served at POST /empresa as core.empresa.cambiar', function () {
    expect(route('core.empresa.cambiar', absolute: false))->toBe('/empresa');
});

it('sends guests to the sign-in page', function () {
    $this->post('/empresa', ['empresa' => 'SGX'])->assertRedirect('/login');
});

it('switches the active company and goes back', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC))
        ->from('/cartera/tablero')
        ->post('/empresa', ['empresa' => 'SDC'])
        ->assertRedirect('/cartera/tablero')
        ->assertSessionHas('status', 'Ahora trabajas en Soluciones y Derivados.')
        ->assertSessionHas(SessionCurrentCompany::SESSION_KEY, 'SDC');
});

it('switches to the group view', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX, CompanyCode::SDC))
        ->from('/cartera/tablero')
        ->post('/empresa', ['empresa' => 'grupo'])
        ->assertRedirect('/cartera/tablero')
        ->assertSessionHas('status', 'Ahora trabajas en Grupo RYR.')
        ->assertSessionHas(SessionCurrentCompany::SESSION_KEY, 'group');
});

it('goes back with an error when the company is not available', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->from('/cartera/tablero')
        ->post('/empresa', ['empresa' => 'SDC'])
        ->assertRedirect('/cartera/tablero')
        ->assertSessionHas('error', 'No tienes acceso a esa empresa.')
        ->assertSessionMissing(SessionCurrentCompany::SESSION_KEY);
});

it('goes back with an error when the group view is not available', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->from('/cartera/tablero')
        ->post('/empresa', ['empresa' => 'grupo'])
        ->assertRedirect('/cartera/tablero')
        ->assertSessionHas('error', 'Necesitas acceso a las dos empresas para ver el Grupo RYR.')
        ->assertSessionMissing(SessionCurrentCompany::SESSION_KEY);
});

it('rejects anything but SGX, SDC or grupo', function (mixed $value) {
    $this->actingAs(CoreUsers::create(Role::Admin))
        ->from('/cartera/tablero')
        ->post('/empresa', ['empresa' => $value])
        ->assertRedirect('/cartera/tablero')
        ->assertSessionHasErrors('empresa')
        ->assertSessionMissing(SessionCurrentCompany::SESSION_KEY);
})->with(['otra empresa' => 'XYZ', 'minúsculas' => 'sgx', 'vacío' => '']);

it('names the field «empresa» in validation messages', function () {
    $this->actingAs(CoreUsers::create(Role::Admin))
        ->post('/empresa', ['empresa' => 'XYZ'])
        ->assertSessionHasErrors(['empresa' => 'El valor elegido en empresa no es válido.']);
});

it('shows the company selector to a user of two companies', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));

    $rendered = (string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>');
    $html = Html::parse($rendered);
    $buttons = $html->all('//button[@name="empresa"]');

    expect($html->one('//form[.//button[@name="empresa"]]')->getAttribute('action'))->toBe(route('core.empresa.cambiar'))
        ->and(array_map(fn (DOMElement $button): string => $button->getAttribute('value'), $buttons))->toBe(['SGX', 'SDC', 'grupo'])
        ->and(array_map(fn (DOMElement $button): string => $button->getAttribute('aria-label'), $buttons))->toBe(['Segurex Latam', 'SDC (Soluciones y Derivados)', 'Grupo RYR'])
        ->and($html->one('//button[@name="empresa"][@aria-pressed="true"]')->getAttribute('value'))->toBe('SGX');

    foreach (BrandPalette::official(CompanyCode::SGX)->toCssVariables() as $variable => $color) {
        expect($rendered)->toContain("{$variable}: {$color};");
    }
});

it('hides the company selector from a user of one company', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC));

    expect(Html::parse((string) $this->blade('<x-layouts.app>Contenido</x-layouts.app>'))->count('//button[@name="empresa"]'))->toBe(0);
});
