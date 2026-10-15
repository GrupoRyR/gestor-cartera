<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\StatusParameters;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;

it('seeds Segurex and Derivados with their default parameters', function () {
    $rows = CompanyRecord::query()->orderByDesc('code')->get()
        ->map(fn (CompanyRecord $row): array => [$row->code, $row->name, $row->grace_days, $row->due_soon_days])
        ->all();

    expect($rows)->toBe([
        [CompanyCode::SGX, 'Segurex Latam', 15, 5],
        [CompanyCode::SDC, 'Soluciones y Derivados', 5, 5],
    ]);
});

it('lists every company in CompanyCode order with its official palette', function () {
    $companies = app(CompanyCatalog::class)->all();

    expect(array_map(fn (Company $company): CompanyCode => $company->code, $companies))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($companies[0]->name)->toBe('Segurex Latam')
        ->and($companies[0]->graceDays)->toBe(15)
        ->and($companies[0]->palette)->toEqual(BrandPalette::official(CompanyCode::SGX))
        ->and($companies[1]->graceDays)->toBe(5)
        ->and($companies[1]->palette)->toEqual(BrandPalette::official(CompanyCode::SDC));
});

it('gives the status parameters of each company', function () {
    $catalog = app(CompanyCatalog::class);

    expect($catalog->parameters(CompanyCode::SGX))->toEqual(new StatusParameters(graceDays: 15, dueSoonDays: 5))
        ->and($catalog->parameters(CompanyCode::SDC))->toEqual(new StatusParameters(graceDays: 5, dueSoonDays: 5));
});

it('reads the parameters admins edit once it is refreshed', function () {
    $catalog = app(CompanyCatalog::class);
    expect($catalog->get(CompanyCode::SGX)->graceDays)->toBe(15);

    CompanyRecord::query()->whereKey('SGX')->update(['name' => 'Segurex', 'grace_days' => 20, 'due_soon_days' => 7]);

    expect($catalog->get(CompanyCode::SGX)->graceDays)->toBe(15);

    $catalog->refresh();

    expect($catalog->get(CompanyCode::SGX)->graceDays)->toBe(20)
        ->and($catalog->get(CompanyCode::SGX)->name)->toBe('Segurex')
        ->and($catalog->parameters(CompanyCode::SGX))->toEqual(new StatusParameters(graceDays: 20, dueSoonDays: 7));
});

it('falls back to the seed defaults and the enum label when a company row is missing', function () {
    config(['core.companies.SDC' => ['grace_days' => 9, 'due_soon_days' => 3]]);
    CompanyRecord::query()->whereKey('SDC')->delete();

    $catalog = app(CompanyCatalog::class);
    $catalog->refresh();

    expect($catalog->get(CompanyCode::SDC)->name)->toBe('Soluciones y Derivados')
        ->and($catalog->get(CompanyCode::SDC)->graceDays)->toBe(9)
        ->and($catalog->parameters(CompanyCode::SDC))->toEqual(new StatusParameters(graceDays: 9, dueSoonDays: 3))
        ->and($catalog->get(CompanyCode::SGX)->graceDays)->toBe(15);
});
