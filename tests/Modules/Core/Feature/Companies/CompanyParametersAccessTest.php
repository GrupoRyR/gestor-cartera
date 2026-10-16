<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
});

dataset('company parameter routes', [
    'list' => ['get', 'core.empresas.index'],
    'edit form' => ['get', 'core.empresas.edit'],
    'update' => ['put', 'core.empresas.update'],
]);

/**
 * The URL of a Parámetros route, about SGX when the route takes a company.
 */
function companyParametersUrl(string $name): string
{
    $takesCompany = str_contains(Route::getRoutes()->getByName($name)?->uri() ?? '', '{empresa}');

    return $takesCompany ? route($name, CompanyCode::SGX->value) : route($name);
}

it('serves the Parámetros screen under /empresas', function () {
    expect(route('core.empresas.index', absolute: false))->toBe('/empresas')
        ->and(route('core.empresas.edit', 'SGX', absolute: false))->toBe('/empresas/SGX/editar')
        ->and(route('core.empresas.update', 'SDC', absolute: false))->toBe('/empresas/SDC');
});

it('sends guests to the sign-in page', function (string $method, string $name) {
    $this->{$method}(companyParametersUrl($name), ['grace_days' => '20', 'due_soon_days' => '5'])->assertRedirect('/login');

    expect(CompanyRecord::query()->findOrFail('SGX')->grace_days)->toBe(15);
})->with('company parameter routes');

it('lets admins and portfolio managers open the screen and save', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC);

    $this->actingAs($user)->get(route('core.empresas.index'))->assertOk();
    $this->actingAs($user)->get(route('core.empresas.edit', 'SGX'))->assertOk();
    $this->actingAs($user)
        ->put(route('core.empresas.update', 'SGX'), ['grace_days' => '20', 'due_soon_days' => '5'])
        ->assertRedirect(route('core.empresas.index'));

    $entries = TeamUsers::audits('core.company.updated');

    expect(CompanyRecord::query()->findOrFail('SGX')->grace_days)->toBe(20)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->user_id)->toBe($user->id)
        ->and($entries[0]->subject_id)->toBe('SGX')
        ->and($entries[0]->changes)->toEqual(['grace_days' => ['old' => 15, 'new' => 20]]);
})->with([
    'admin' => [Role::Admin],
    'gerente de cartera' => [Role::PortfolioManager],
]);

it('forbids every role but admin and gerente de cartera', function (?Role $role, string $method, string $name) {
    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC))
        ->{$method}(companyParametersUrl($name), ['grace_days' => '20', 'due_soon_days' => '5'])
        ->assertForbidden();

    expect(CompanyRecord::query()->findOrFail('SGX')->grace_days)->toBe(15)
        ->and(AuditLogRecord::query()->where('action', 'core.company.updated')->count())->toBe(0);
})->with([
    'analista de cartera' => [Role::PortfolioAnalyst],
    'asesor' => [Role::SalesAdvisor],
    'gerente comercial' => [Role::SalesManager],
    'tesorería' => [Role::Treasury],
    'sin rol' => [null],
])->with('company parameter routes');

it('finds only the codes of the companies', function (string $code) {
    $admin = TeamUsers::admin();

    $this->actingAs($admin)->get("/empresas/{$code}/editar")->assertNotFound();
    $this->actingAs($admin)->put("/empresas/{$code}", ['grace_days' => '20', 'due_soon_days' => '5'])->assertNotFound();

    expect(AuditLogRecord::query()->where('action', 'core.company.updated')->count())->toBe(0);
})->with(['unknown code' => ['XYZ'], 'lowercase code' => ['sgx'], 'group view' => ['grupo']]);

it('shows Parámetros in the admin menu, after Equipo', function () {
    $admin = TeamUsers::admin();

    $html = TeamUsers::html($this->actingAs($admin)->get('/empresas')->assertOk());
    $link = $html->one('//nav[@id="menu-principal"]//a[@aria-current="page"]');
    $entries = array_map(
        fn (DOMElement $entry): string => Html::clean($entry->textContent),
        $html->all('//nav[@id="menu-principal"]//a[@href="'.route('core.equipo.index').'" or @href="'.route('core.empresas.index').'"]'),
    );

    expect(Html::clean($link->textContent))->toBe('Parámetros')
        ->and($link->getAttribute('href'))->toBe(route('core.empresas.index'))
        ->and($entries)->toBe(['Equipo', 'Parámetros'])
        ->and($html->text('//nav[@id="menu-principal"]//h2'))->toBe('Administración')
        ->and($html->text('//h1'))->toBe('Parámetros')
        ->and($html->text('//title'))->toBe('Parámetros · '.config('app.name'));

    // Equipo stays first, so it is still the admin's home.
    $this->actingAs($admin)->get('/')->assertRedirect(route('core.equipo.index'));
});

it('shows Parámetros in the portfolio manager menu, without Equipo', function () {
    $manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX, CompanyCode::SDC);

    $html = TeamUsers::html($this->actingAs($manager)->get('/empresas')->assertOk());
    $link = $html->one('//nav[@id="menu-principal"]//a[@aria-current="page"]');

    expect(Html::clean($link->textContent))->toBe('Parámetros')
        ->and($link->getAttribute('href'))->toBe(route('core.empresas.index'))
        ->and($html->count('//nav[@id="menu-principal"]//a[@href="'.route('core.equipo.index').'"]'))->toBe(0)
        ->and($html->text('//h1'))->toBe('Parámetros');
});

it('shows Parámetros only to admins and portfolio managers', function () {
    $visibleKeys = fn (User $user): array => array_map(
        fn (NavItem $item): string => $item->key,
        app(NavRegistry::class)->visibleTo($user),
    );

    expect($visibleKeys(TeamUsers::admin()))->toContain('core.empresas')
        ->and($visibleKeys(CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX)))->toContain('core.empresas');

    foreach ([Role::PortfolioAnalyst, Role::SalesAdvisor, Role::SalesManager, Role::Treasury, null] as $role) {
        expect($visibleKeys(CoreUsers::create($role, CompanyCode::SGX)))->not->toContain('core.empresas');
    }
});
