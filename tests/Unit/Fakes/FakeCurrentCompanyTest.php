<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use Tests\Fakes\FakeCurrentCompany;

/**
 * @param  list<Company>  $companies
 * @return list<CompanyCode>
 */
function companyCodes(array $companies): array
{
    return array_map(fn (Company $company): CompanyCode => $company->code, $companies);
}

it('starts in Segurex with both companies available', function () {
    $current = new FakeCurrentCompany;

    expect($current->active()?->code)->toBe(CompanyCode::SGX)
        ->and($current->active()?->name)->toBe('Segurex Latam')
        ->and($current->isGroup())->toBeFalse()
        ->and(companyCodes($current->scope()))->toBe([CompanyCode::SGX])
        ->and(companyCodes($current->available()))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($current->palette())->toEqual(BrandPalette::official(CompanyCode::SGX));
});

it('uses the default grace days of each company', function () {
    $current = new FakeCurrentCompany;

    expect($current->of(CompanyCode::SGX)->graceDays)->toBe(15)
        ->and($current->of(CompanyCode::SDC)->graceDays)->toBe(5);
});

it('accepts other grace days', function () {
    $current = new FakeCurrentCompany(graceDays: ['SDC' => 8]);

    expect($current->of(CompanyCode::SGX)->graceDays)->toBe(15)
        ->and($current->of(CompanyCode::SDC)->graceDays)->toBe(8);
});

it('covers every available company in the group view with the neutral palette', function () {
    $current = FakeCurrentCompany::group();

    expect($current->active())->toBeNull()
        ->and($current->isGroup())->toBeTrue()
        ->and(companyCodes($current->scope()))->toBe([CompanyCode::SGX, CompanyCode::SDC])
        ->and($current->palette())->toEqual(BrandPalette::neutral());
});

it('gives guests no company and the neutral palette', function () {
    $current = FakeCurrentCompany::guest();

    expect($current->active())->toBeNull()
        ->and($current->isGroup())->toBeFalse()
        ->and($current->scope())->toBe([])
        ->and($current->available())->toBe([])
        ->and($current->palette())->toEqual(BrandPalette::neutral());
});

it('switches between companies and the group view', function () {
    $current = new FakeCurrentCompany;

    $current->switchTo(CompanyCode::SDC);

    expect($current->active()?->code)->toBe(CompanyCode::SDC)
        ->and($current->palette())->toEqual(BrandPalette::official(CompanyCode::SDC));

    $current->switchTo(null);

    expect($current->isGroup())->toBeTrue()
        ->and($current->active())->toBeNull();
});

it('refuses a company the user may not see', function () {
    $current = new FakeCurrentCompany(available: [CompanyCode::SGX]);

    expect(fn () => $current->switchTo(CompanyCode::SDC))
        ->toThrow(fn (CompanyNotAvailable $e) => expect($e->company)->toBe(CompanyCode::SDC));

    expect($current->active()?->code)->toBe(CompanyCode::SGX);
});

it('refuses the group view with a single available company', function () {
    $current = new FakeCurrentCompany(available: [CompanyCode::SDC], active: CompanyCode::SDC);

    expect(fn () => $current->switchTo(null))
        ->toThrow(CompanyNotAvailable::class, 'group view');
});

it('describes any company whatever the user may see', function () {
    $current = new FakeCurrentCompany(available: [CompanyCode::SGX]);

    expect($current->of(CompanyCode::SDC)->name)->toBe('Soluciones y Derivados')
        ->and($current->of(CompanyCode::SDC)->palette)->toEqual(BrandPalette::official(CompanyCode::SDC));
});

it('rejects an active company that is not available', function () {
    expect(fn () => new FakeCurrentCompany(active: CompanyCode::SDC, available: [CompanyCode::SGX]))
        ->toThrow(CompanyNotAvailable::class);
});
