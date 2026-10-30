<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;

it('keeps the official Segurex palette', function () {
    expect(BrandPalette::official(CompanyCode::SGX))->toEqual(new BrandPalette(
        accent: '#f26522',
        button: '#c6531c',
        ink: '#943e15',
        text: '#58595b',
        tint: '#fcede4',
        head: '#58595b',
        darkAccent: '#f26522',
        darkInk: '#f26522',
        darkText: '#cacbcd',
        darkTint: '#f265221a',
        darkHead: '#2b2c2f',
    ));
});

it('keeps the official Soluciones y Derivados palette', function () {
    expect(BrandPalette::official(CompanyCode::SDC))->toEqual(new BrandPalette(
        accent: '#0b5fa5',
        button: '#0b5fa5',
        ink: '#073b66',
        text: '#073b66',
        tint: '#e5eef7',
        head: '#073b66',
        darkAccent: '#5e9ad0',
        darkInk: '#5e9ad0',
        darkText: '#cacbcd',
        darkTint: '#5e9ad01c',
        darkHead: '#0a2540',
    ));
});

it('offers a neutral palette for the group view and guests', function () {
    expect(BrandPalette::neutral())->toEqual(new BrandPalette(
        accent: '#5e6168',
        button: '#17191c',
        ink: '#17191c',
        text: '#45484e',
        tint: '#f0f0ec',
        head: '#26272b',
        darkAccent: '#a3a6ad',
        darkInk: '#f2f2ef',
        darkText: '#cacbcd',
        darkTint: '#2c2e33',
        darkHead: '#26272b',
    ));
});

it('exposes the light palette as --brand-* variables', function () {
    expect(BrandPalette::official(CompanyCode::SGX)->toCssVariables())->toBe([
        '--brand-accent' => '#f26522',
        '--brand-button' => '#c6531c',
        '--brand-ink' => '#943e15',
        '--brand-text' => '#58595b',
        '--brand-tint' => '#fcede4',
        '--brand-head' => '#58595b',
    ]);
});

it('swaps everything but the button for its dark variant in dark mode', function () {
    expect(BrandPalette::official(CompanyCode::SDC)->toCssVariables(dark: true))->toBe([
        '--brand-accent' => '#5e9ad0',
        '--brand-button' => '#0b5fa5',
        '--brand-ink' => '#5e9ad0',
        '--brand-text' => '#cacbcd',
        '--brand-tint' => '#5e9ad01c',
        '--brand-head' => '#0a2540',
    ]);
});

it('rejects values that are not hex colors', function (string $value) {
    expect(fn () => new BrandPalette(
        accent: $value,
        button: '#c6531c',
        ink: '#943e15',
        text: '#58595b',
        tint: '#fcede4',
        head: '#58595b',
        darkAccent: '#f26522',
        darkInk: '#f26522',
        darkText: '#cacbcd',
        darkTint: '#f265221a',
        darkHead: '#2b2c2f',
    ))->toThrow(InvalidArgumentException::class, 'accent');
})->with([
    'named color' => 'orange',
    'missing hash' => 'f26522',
    'css injection' => '#fff; } body { display: none',
    'five digits' => '#f2652',
]);
