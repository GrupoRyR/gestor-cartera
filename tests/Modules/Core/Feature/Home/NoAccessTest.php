<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\CoreUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
});

it('is served at GET /sin-acceso as core.sin-acceso', function () {
    expect(route('core.sin-acceso', absolute: false))->toBe('/sin-acceso');
});

it('sends guests to the sign-in page', function () {
    $this->get('/sin-acceso')->assertRedirect('/login');
});

it('asks a user without a role to request access, on the neutral guest layout', function () {
    $user = CoreUsers::create(null, CompanyCode::SGX);

    $rendered = $this->actingAs($user)->get('/sin-acceso')->assertOk()->assertViewIs('core::sin-acceso')->getContent() ?: '';
    $html = Html::parse($rendered);

    expect($html->text('//h1'))->toBe('Todavía no tienes acceso')
        ->and($html->text('//main'))
        ->toContain('Tu cuenta de Microsoft ya está registrada, pero aún no tiene un rol asignado.')
        ->toContain('Pide al área de tecnología (TI) que te asigne uno.')
        ->toContain($user->email)
        ->and($html->count('//body/header/p[@data-part="brand"]'))->toBe(1)
        ->and($html->count('//*[@data-part="user"]'))->toBe(0)
        ->and($html->count('//nav'))->toBe(0)
        ->and($html->count('//form'))->toBe(0);

    foreach (BrandPalette::neutral()->toCssVariables() as $variable => $color) {
        expect($rendered)->toContain("{$variable}: {$color};");
    }
});

it('offers to sign out once core.logout exists', function () {
    Route::middleware('web')->post('/logout', fn () => null)->name('core.logout');
    Route::getRoutes()->refreshNameLookups();

    $html = Html::parse($this->actingAs(CoreUsers::create(null))->get('/sin-acceso')->assertOk()->getContent() ?: '');
    $form = $html->one('//form');

    expect(strtoupper($form->getAttribute('method')))->toBe('POST')
        ->and($form->getAttribute('action'))->toBe(route('core.logout'))
        ->and($html->count('//form//input[@name="_token"]'))->toBe(1)
        ->and($html->text('//form//button[@type="submit"]'))->toBe('Salir');
});

it('sends a user who already has access home', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->get('/sin-acceso')
        ->assertRedirect(route('core.inicio'));
});
