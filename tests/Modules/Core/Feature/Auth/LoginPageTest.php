<?php

use App\Models\User;
use App\Modules\Core\Contracts\Data\BrandPalette;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\FakeIdentityProvider;

beforeEach(function () {
    config(['services.microsoft.tenant' => FakeIdentityProvider::TENANT]);
    FakeIdentityProvider::install($this->app);
});

it('serves the sign-in routes Microsoft and the layouts expect', function () {
    expect(route('core.login', absolute: false))->toBe('/login')
        ->and(route('core.auth.redirect', absolute: false))->toBe('/auth/microsoft')
        ->and(route('core.auth.callback', absolute: false))->toBe('/auth/microsoft/callback');
});

it('shows guests the Microsoft sign-in button on the neutral guest layout', function () {
    $rendered = $this->get('/login')->assertOk()->assertViewIs('core::auth.login')->getContent() ?: '';
    $html = Html::parse($rendered);

    expect($html->text('//title'))->toBe('Ingresar · '.config('app.name'))
        ->and($html->text('//h1'))->toBe('Ingresa al gestor de cartera')
        ->and($html->one('//main//a[normalize-space(.)="Ingresar con Microsoft"]')->getAttribute('href'))
        ->toBe(route('core.auth.redirect'))
        ->and($html->count('//nav'))->toBe(0)
        ->and($html->count('//form'))->toBe(0);

    foreach (BrandPalette::neutral()->toCssVariables() as $variable => $color) {
        expect($rendered)->toContain("{$variable}: {$color};");
    }
});

it('shows why the last sign-in failed', function () {
    $html = Html::parse($this->withSession(['error' => 'No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.'])
        ->get('/login')
        ->getContent() ?: '');

    expect($html->text('//main//*[@role="alert"]'))->toBe('No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.');
});

it('sends signed-in users home', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertRedirect('/');
})->with(['sign-in page' => ['/login'], 'Microsoft redirect' => ['/auth/microsoft']]);

it('sends guests to Microsoft', function () {
    $this->get('/auth/microsoft')->assertRedirect(FakeIdentityProvider::AUTHORIZE_URL);
});
