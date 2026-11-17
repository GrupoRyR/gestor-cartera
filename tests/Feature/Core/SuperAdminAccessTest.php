<?php

use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Access\CompanyAccess;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use Database\Seeders\DatabaseSeeder;
use Tests\Modules\Core\Support\CoreUsers;

it('gives the superadmin every company without company_user rows', function () {
    $superAdmin = CoreUsers::create(Role::SuperAdmin);

    expect($superAdmin->companies()->count())->toBe(0)
        ->and(app(CompanyAccess::class)->companiesFor($superAdmin))->toBe([CompanyCode::SGX, CompanyCode::SDC]);
});

it('lets the superadmin see every client of both companies', function () {
    $superAdmin = CoreUsers::create(Role::SuperAdmin);
    $this->actingAs($superAdmin);

    // Every company it may select, as ClientReach asks for it.
    $scope = PortfolioScope::forUser($superAdmin, app(CurrentCompany::class), CompanyCode::cases());

    expect($scope->companies)->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($scope->everyClient)->toBeTrue()
        ->and($scope->isEmpty())->toBeFalse();
});

it('never offers the superadmin as a client manager', function () {
    $this->seed(DatabaseSeeder::class);

    $analyst = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);
    $superAdmin = CoreUsers::create(Role::SuperAdmin, CompanyCode::SGX, CompanyCode::SDC);
    $this->actingAs($superAdmin);

    $ids = array_map(fn (UserRef $user): int => $user->id, app(ClientAssignmentService::class)->assignees());

    expect($ids)->toContain($analyst->id)
        ->and($ids)->not->toContain($superAdmin->id);
});
