<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;

it('keeps the official Segurex palette', function () {
    expect(BrandPalette::official(CompanyCode::SGX))->toEqual(new BrandPalette(
        primary: '#ff671f',
        accent: '#666666',
        dark: '#8f360b',
        button: '#c24c15',
        tint: '#feeee8',
        ink: '#8d350c',
        darkTint: '#432519',
        darkInk: '#ffa17d',
    ));
});

it('keeps the official Soluciones y Derivados palette', function () {
    expect(BrandPalette::official(CompanyCode::SDC))->toEqual(new BrandPalette(
        primary: '#183f94',
        accent: '#3e60a8',
        dark: '#03256c',
        button: '#183f94',
        tint: '#ecf2fe',
        ink: '#183f94',
        darkTint: '#202d47',
        darkInk: '#97bafe',
    ));
});

it('offers a neutral palette for the group view and guests', function () {
    expect(BrandPalette::neutral())->toEqual(new BrandPalette(
        primary: '#17191c',
        accent: '#5e6168',
        dark: '#17191c',
        button: '#17191c',
        tint: '#f0f0ec',
        ink: '#17191c',
        darkTint: '#2c2e33',
        darkInk: '#f2f2ef',
    ));
});

it('exposes the light palette as --brand-* variables', function () {
    expect(BrandPalette::official(CompanyCode::SGX)->toCssVariables())->toBe([
        '--brand-primary' => '#ff671f',
        '--brand-accent' => '#666666',
        '--brand-dark' => '#8f360b',
        '--brand-button' => '#c24c15',
        '--brand-tint' => '#feeee8',
        '--brand-ink' => '#8d350c',
    ]);
});

it('swaps tint and ink for their dark variants in dark mode', function () {
    expect(BrandPalette::official(CompanyCode::SDC)->toCssVariables(dark: true))->toBe([
        '--brand-primary' => '#183f94',
        '--brand-accent' => '#3e60a8',
        '--brand-dark' => '#03256c',
        '--brand-button' => '#183f94',
        '--brand-tint' => '#202d47',
        '--brand-ink' => '#97bafe',
    ]);
});

it('rejects values that are not hex colors', function (string $value) {
    expect(fn () => new BrandPalette(
        primary: $value,
        accent: '#666666',
        dark: '#8f360b',
        button: '#c24c15',
        tint: '#feeee8',
        ink: '#8d350c',
        darkTint: '#432519',
        darkInk: '#ffa17d',
    ))->toThrow(InvalidArgumentException::class, 'primary');
})->with([
    'named color' => 'orange',
    'missing hash' => 'ff671f',
    'css injection' => '#fff; } body { display: none',
    'five digits' => '#ff671',
]);
