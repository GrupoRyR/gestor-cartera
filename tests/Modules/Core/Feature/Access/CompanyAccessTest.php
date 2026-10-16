<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Access\CompanyAccess;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Tests\Modules\Core\Support\CoreUsers;

it('relates users to their companies through company_user', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SDC);

    $companies = $user->companies()->get();

    expect($companies->map(fn (CompanyRecord $company): CompanyCode => $company->code)->all())->toBe([CompanyCode::SDC])
        ->and($companies->first()?->pivot?->created_at)->not->toBeNull();
});

it('gives no company to guests and unsaved users', function () {
    $access = app(CompanyAccess::class);

    expect($access->companiesFor(null))->toBe([])
        ->and($access->companiesFor(User::factory()->make()))->toBe([]);
});

it('gives no company to users without a role, even with assignments', function () {
    $user = CoreUsers::create(null, CompanyCode::SGX, CompanyCode::SDC);

    expect(app(CompanyAccess::class)->companiesFor($user))->toBe([]);
});

it('gives no company to inactive users', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
    $user->update(['is_active' => false]);

    expect(app(CompanyAccess::class)->companiesFor($user))->toBe([]);
});

it('gives no company to deleted users', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
    $user->delete();

    expect(app(CompanyAccess::class)->companiesFor($user))->toBe([]);
});

it('reads users that were never reloaded and lack deleted_at', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
    $user->wasRecentlyCreated = false; // as actingAs() leaves it

    expect($user->getAttributes())->not->toHaveKey('deleted_at')
        ->and(app(CompanyAccess::class)->companiesFor($user))->toBe([CompanyCode::SGX]);
});

it('gives admins every company without assignments', function () {
    $user = CoreUsers::create(Role::Admin);

    expect(app(CompanyAccess::class)->companiesFor($user))->toBe([CompanyCode::SGX, CompanyCode::SDC]);
});

it('gives other roles their assigned companies in CompanyCode order', function () {
    $access = app(CompanyAccess::class);

    expect($access->companiesFor(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SDC)))->toBe([CompanyCode::SDC])
        ->and($access->companiesFor(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SDC, CompanyCode::SGX)))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($access->companiesFor(CoreUsers::create(Role::SalesManager)))->toBe([]);
});
