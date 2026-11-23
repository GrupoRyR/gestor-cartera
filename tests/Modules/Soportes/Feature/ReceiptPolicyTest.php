<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * A client of advisor $advisorCode, known to the portfolio fake.
 */
function soportesClient(int $advisorCode, CompanyCode $company = CompanyCode::SGX): ClientSummary
{
    $summary = Make::client(company: $company, advisor: Make::advisor(company: $company, code: $advisorCode));
    test()->fakes->cartera->addClient(Make::clientDetail($summary));

    return $summary;
}

/**
 * A pending receipt of $client uploaded by $uploader.
 */
function soportesReceipt(ClientSummary $client, User $uploader): Receipt
{
    return Receipt::factory()->forClient($client)->uploadedBy($uploader)->create();
}

/**
 * Only $companies are available to the user from now on.
 *
 * @param  list<CompanyCode>  $companies
 */
function soportesAvailable(array $companies): void
{
    app()->instance(CurrentCompany::class, new FakeCurrentCompany($companies[0], $companies));
}

describe('viewAny and create', function () {
    it('lets the roles with soportes.view open the inbox', function (Role $role) {
        expect(SoportesUsers::create($role)->can('viewAny', Receipt::class))->toBeTrue();
    })->with([[Role::SalesAdvisor], [Role::PortfolioAnalyst], [Role::PortfolioManager], [Role::SuperAdmin]]);

    it('keeps the inbox closed to everyone else', function (?Role $role) {
        expect(SoportesUsers::create($role)->can('viewAny', Receipt::class))->toBeFalse();
    })->with([[Role::Treasury], [Role::Admin], [Role::SalesManager], [null]]);

    it('lets advisors and the portfolio team upload, never the superadmin', function () {
        expect(SoportesUsers::advisor()->can('create', Receipt::class))->toBeTrue()
            ->and(SoportesUsers::analyst()->can('create', Receipt::class))->toBeTrue()
            ->and(SoportesUsers::create(Role::SuperAdmin)->can('create', Receipt::class))->toBeFalse()
            ->and(SoportesUsers::create(Role::Treasury)->can('create', Receipt::class))->toBeFalse();
    });
});

describe('view', function () {
    it('lets a reviewer open a receipt of a company available to them', function () {
        $receipt = soportesReceipt(soportesClient(10), SoportesUsers::advisor());

        expect(SoportesUsers::analyst()->can('view', $receipt))->toBeTrue();
    });

    it('keeps a reviewer out of a receipt of a company not available to them', function () {
        $receipt = soportesReceipt(soportesClient(10, CompanyCode::SDC), SoportesUsers::advisor());
        soportesAvailable([CompanyCode::SGX]);

        expect(SoportesUsers::analyst()->can('view', $receipt))->toBeFalse();
    });

    it('lets the advisor open the receipt they uploaded', function () {
        $advisor = SoportesUsers::advisor();
        $receipt = soportesReceipt(soportesClient(10), $advisor);
        $this->fakes->cartera->restrictToAdvisor(99);

        expect($advisor->can('view', $receipt))->toBeTrue();
    });

    it('lets the advisor open a receipt the analyst uploaded for one of their clients', function () {
        $receipt = soportesReceipt(soportesClient(10), SoportesUsers::analyst());
        $this->fakes->cartera->restrictToAdvisor(10);

        expect(SoportesUsers::advisor('Asesora A')->can('view', $receipt))->toBeTrue();
    });

    it('keeps another advisor out of a receipt that is neither theirs nor of their clients', function () {
        $receipt = soportesReceipt(soportesClient(10), SoportesUsers::advisor('Asesora A'));
        $this->fakes->cartera->restrictToAdvisor(20);

        expect(SoportesUsers::advisor('Asesor B')->can('view', $receipt))->toBeFalse();
    });

    it('keeps a user without soportes.view out even of their own receipt', function () {
        $user = SoportesUsers::create(Role::Treasury);
        $receipt = soportesReceipt(soportesClient(10), $user);

        expect($user->can('view', $receipt))->toBeFalse();
    });
});

describe('review', function () {
    it('lets a reviewer review a pending receipt of an available company', function () {
        $receipt = soportesReceipt(soportesClient(10), SoportesUsers::advisor());

        expect(SoportesUsers::analyst()->can('review', $receipt))->toBeTrue()
            ->and(SoportesUsers::create(Role::PortfolioManager)->can('review', $receipt))->toBeTrue();
    });

    it('refuses to review an applied or rejected receipt', function (string $state) {
        $receipt = Receipt::factory()->forClient(soportesClient(10))->uploadedBy(SoportesUsers::advisor())->{$state}()->create();

        expect(SoportesUsers::analyst()->can('review', $receipt))->toBeFalse();
    })->with(['applied', 'rejected']);

    it('refuses to review a receipt of a company not available to the reviewer', function () {
        $receipt = soportesReceipt(soportesClient(10, CompanyCode::SDC), SoportesUsers::advisor());
        soportesAvailable([CompanyCode::SGX]);

        expect(SoportesUsers::analyst()->can('review', $receipt))->toBeFalse();
    });

    it('never lets the advisor or the superadmin review', function () {
        $receipt = soportesReceipt(soportesClient(10), SoportesUsers::advisor());
        $this->fakes->cartera->restrictToAdvisor(10);

        expect(SoportesUsers::advisor()->can('review', $receipt))->toBeFalse()
            ->and(SoportesUsers::create(Role::SuperAdmin)->can('review', $receipt))->toBeFalse();
    });
});
