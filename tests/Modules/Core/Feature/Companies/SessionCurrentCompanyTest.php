<?php

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use App\Modules\Core\Infrastructure\Companies\SessionCurrentCompany;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Support\Facades\Route;
use Tests\Modules\Core\Support\CoreUsers;

/**
 * @param  list<Company>  $companies
 * @return list<CompanyCode>
 */
function codesOf(array $companies): array
{
    return array_map(fn (Company $company): CompanyCode => $company->code, $companies);
}

beforeEach(function () {
    // What the next request sees as the current company.
    Route::middleware(['web', 'auth'])->get('/_test/empresa-activa', fn (CurrentCompany $current) => [
        'active' => $current->active()?->code->value,
        'group' => $current->isGroup(),
        'scope' => array_map(fn (CompanyCode $code): string => $code->value, codesOf($current->scope())),
    ]);
});

it('is the CurrentCompany of the container', function () {
    expect(app(CurrentCompany::class))->toBeInstanceOf(SessionCurrentCompany::class);
});

it('gives guests no company and the neutral palette', function () {
    $current = app(CurrentCompany::class);

    expect($current->active())->toBeNull()
        ->and($current->isGroup())->toBeFalse()
        ->and($current->scope())->toBe([])
        ->and($current->available())->toBe([])
        ->and($current->palette())->toEqual(BrandPalette::neutral());
});

it('gives users without a role no company and the neutral palette', function () {
    $this->actingAs(CoreUsers::create(null, CompanyCode::SGX));
    $current = app(CurrentCompany::class);

    expect($current->available())->toBe([])
        ->and($current->palette())->toEqual(BrandPalette::neutral());
});

it('starts in the first company the user may see', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SDC, CompanyCode::SGX));
    $current = app(CurrentCompany::class);

    expect($current->active()?->code)->toBe(CompanyCode::SGX)
        ->and($current->isGroup())->toBeFalse()
        ->and(codesOf($current->scope()))->toBe([CompanyCode::SGX])
        ->and(codesOf($current->available()))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($current->palette())->toEqual(BrandPalette::official(CompanyCode::SGX));
});

it('starts in the only company of a single-company user', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC));

    expect(app(CurrentCompany::class)->active()?->code)->toBe(CompanyCode::SDC);
});

it('switches to the group view of every available company', function () {
    $this->actingAs(CoreUsers::create(Role::Admin));
    $current = app(CurrentCompany::class);

    $current->switchTo(null);

    expect($current->active())->toBeNull()
        ->and($current->isGroup())->toBeTrue()
        ->and(codesOf($current->scope()))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($current->palette())->toEqual(BrandPalette::neutral());
});

it('refuses the group view to a single-company user', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX));

    app(CurrentCompany::class)->switchTo(null);
})->throws(CompanyNotAvailable::class, 'group view');

it('refuses a company the user may not see and keeps the active one', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX));
    $current = app(CurrentCompany::class);

    expect(fn () => $current->switchTo(CompanyCode::SDC))->toThrow(CompanyNotAvailable::forCompany(CompanyCode::SDC))
        ->and($current->active()?->code)->toBe(CompanyCode::SGX);
});

it('refuses every switch to guests', function () {
    $current = app(CurrentCompany::class);

    expect(fn () => $current->switchTo(CompanyCode::SGX))->toThrow(CompanyNotAvailable::class)
        ->and(fn () => $current->switchTo(null))->toThrow(CompanyNotAvailable::class);
});

it('keeps the choice in memory when there is no session', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));
    $current = app(CurrentCompany::class);

    $current->switchTo(CompanyCode::SDC);

    expect($current->active()?->code)->toBe(CompanyCode::SDC)
        ->and($current->palette())->toEqual(BrandPalette::official(CompanyCode::SDC))
        ->and(session()->has(SessionCurrentCompany::SESSION_KEY))->toBeFalse();
});

it('stores the choice in the session of the request', function () {
    Route::middleware(['web', 'auth'])->post('/_test/empresa-activa/{choice}', function (CurrentCompany $current, string $choice): void {
        $current->switchTo($choice === 'group' ? null : CompanyCode::from($choice));
    });
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));

    $this->post('/_test/empresa-activa/SDC')->assertSessionHas(SessionCurrentCompany::SESSION_KEY, 'SDC');
    $this->post('/_test/empresa-activa/group')->assertSessionHas(SessionCurrentCompany::SESSION_KEY, 'group');
});

it('reads the choice of the session on the next requests', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC))
        ->withSession([SessionCurrentCompany::SESSION_KEY => 'SDC'])
        ->get('/_test/empresa-activa')
        ->assertExactJson(['active' => 'SDC', 'group' => false, 'scope' => ['SDC']]);

    app()->forgetScopedInstances();

    $this->withSession([SessionCurrentCompany::SESSION_KEY => 'group'])
        ->get('/_test/empresa-activa')
        ->assertExactJson(['active' => null, 'group' => true, 'scope' => ['SGX', 'SDC']]);
});

it('falls back to the first company once access to the chosen one is revoked', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);
    $user->companies()->detach('SDC');

    $this->actingAs($user)
        ->withSession([SessionCurrentCompany::SESSION_KEY => 'SDC'])
        ->get('/_test/empresa-activa')
        ->assertExactJson(['active' => 'SGX', 'group' => false, 'scope' => ['SGX']]);
});

it('leaves the group view once the user keeps a single company', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);
    $user->companies()->detach('SGX');

    $this->actingAs($user)
        ->withSession([SessionCurrentCompany::SESSION_KEY => 'group'])
        ->get('/_test/empresa-activa')
        ->assertExactJson(['active' => 'SDC', 'group' => false, 'scope' => ['SDC']]);
});

it('gives any company with its parameters, whoever is signed in', function () {
    CompanyRecord::query()->whereKey('SDC')->update(['grace_days' => 8]);

    $company = app(CurrentCompany::class)->of(CompanyCode::SDC);

    expect($company->code)->toBe(CompanyCode::SDC)
        ->and($company->name)->toBe('Soluciones y Derivados')
        ->and($company->graceDays)->toBe(8)
        ->and($company->palette)->toEqual(BrandPalette::official(CompanyCode::SDC));
});
