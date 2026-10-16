<?php

use App\Models\User;
use App\Modules\Asesores\Database\Seeders\AsesoresPermissionSeeder;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Support\Facades\Gate;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * The policies decide what the screens offer; the data scope itself (who can
 * see which client) is Core's, behind CarteraRepository.
 */
beforeEach(function () {
    $this->seed(AsesoresPermissionSeeder::class);
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * A client of $company whose SAP advisor is linked to $user.
 */
function clientOf(User $user, CompanyCode $company = CompanyCode::SGX): ClientDetail
{
    return Make::clientDetail(Make::client(
        company: $company,
        advisor: Make::advisor(company: $company, code: 12, userId: $user->getKey()),
    ));
}

it('lets an advisor, and a sales manager, view a client of their companies', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('view', clientOf($user)))->toBeTrue();
})->with([Role::SalesAdvisor, Role::SalesManager]);

it('does not let other roles view a client through the advisor viewer', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('view', clientOf($user)))->toBeFalse();
})->with([Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst, Role::Treasury]);

it('does not let a user view a client of a company they cannot work in', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

    expect(Gate::forUser($user)->allows('view', clientOf($user, CompanyCode::SDC)))->toBeFalse();
});

it('lets the advisor of a SGX client manage its commitments', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('manage', clientOf($user)))->toBeTrue();
});

it('does not let an advisor manage the commitments of a client of another advisor', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $other = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('manage', clientOf($other)))->toBeFalse();
});

it('does not let an advisor manage commitments in SDC, where the analyst writes them', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX, CompanyCode::SDC);

    expect(Gate::forUser($user)->allows('manage', clientOf($user, CompanyCode::SDC)))->toBeFalse();
});

it('does not let a sales manager manage commitments', function () {
    $user = CoreUsers::create(Role::SalesManager, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('manage', clientOf($user)))->toBeFalse();
});

it('lets an advisor edit only a pending commitment', function (CommitmentStatus $status, bool $allowed) {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    expect(Gate::forUser($user)->allows('update', Make::commitment(status: $status)))->toBe($allowed);
})->with([
    'pending' => [CommitmentStatus::Pending, true],
    'broken' => [CommitmentStatus::Broken, false],
    'fulfilled' => [CommitmentStatus::Fulfilled, false],
    'cancelled' => [CommitmentStatus::Cancelled, false],
]);

it('lets an advisor close a pending or broken commitment, never a closed one', function (CommitmentStatus $status, bool $allowed) {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $commitment = Make::commitment(status: $status);

    expect(Gate::forUser($user)->allows('fulfill', $commitment))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('cancel', $commitment))->toBe($allowed);
})->with([
    'pending' => [CommitmentStatus::Pending, true],
    'broken' => [CommitmentStatus::Broken, true],
    'fulfilled' => [CommitmentStatus::Fulfilled, false],
    'cancelled' => [CommitmentStatus::Cancelled, false],
]);

it('does not let anyone but an advisor change a commitment', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC);
    $commitment = Make::commitment();

    expect(Gate::forUser($user)->allows('update', $commitment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('fulfill', $commitment))->toBeFalse()
        ->and(Gate::forUser($user)->allows('cancel', $commitment))->toBeFalse();
})->with([Role::SalesManager, Role::PortfolioManager, Role::PortfolioAnalyst, Role::Admin]);

it('does not let an advisor change a SDC commitment', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX, CompanyCode::SDC);

    expect(Gate::forUser($user)->allows('update', Make::commitment(company: CompanyCode::SDC)))->toBeFalse();
});
