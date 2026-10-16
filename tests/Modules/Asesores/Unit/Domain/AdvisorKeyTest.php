<?php

use App\Modules\Asesores\Domain\Portfolio\AdvisorKey;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Tests\Fakes\Make;

it('reads a company and a SAP code', function () {
    $key = AdvisorKey::parse('SGX-12');

    expect($key?->company)->toBe(CompanyCode::SGX)
        ->and($key?->code)->toBe(12)
        ->and($key?->value())->toBe('SGX-12');
});

it('tells the same code apart in each company', function () {
    expect(AdvisorKey::parse('SDC-12')?->company)->toBe(CompanyCode::SDC)
        ->and(AdvisorKey::parse('SDC-12')?->value())->not->toBe(AdvisorKey::parse('SGX-12')?->value());
});

it('ignores anything that is not a company and a number', function (?string $value) {
    expect(AdvisorKey::parse($value))->toBeNull();
})->with([
    'nothing' => [null],
    'empty' => [''],
    'bare code' => ['12'],
    'unknown company' => ['XYZ-12'],
    'lowercase company' => ['sgx-12'],
    'negative' => ['SGX--1'],
    'text code' => ['SGX-ab'],
    'trailing text' => ['SGX-12;drop'],
    'too long' => ['SGX-12345678901'],
    'padded' => [' SGX-12'],
]);

it('is built from an advisor and matches only that advisor', function () {
    $advisor = Make::advisor(CompanyCode::SDC, 40);

    expect(AdvisorKey::of($advisor)->value())->toBe('SDC-40')
        ->and(AdvisorKey::of($advisor)->matches($advisor))->toBeTrue()
        ->and(AdvisorKey::of($advisor)->matches(Make::advisor(CompanyCode::SGX, 40)))->toBeFalse()
        ->and(AdvisorKey::of($advisor)->matches(Make::advisor(CompanyCode::SDC, 41)))->toBeFalse();
});
