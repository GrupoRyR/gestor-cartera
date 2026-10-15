<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\File;

/**
 * Rules of the ryr-design skill (Part A.6) checked on the files phase 0b
 * owns, so a later change cannot slip a company color or an all-caps label
 * into the shared UI.
 *
 * @return list<string>
 */
function designFiles(): array
{
    return array_values(array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        [
            ...File::allFiles(resource_path('css')),
            ...File::allFiles(resource_path('js')),
            ...File::allFiles(resource_path('views/components')),
            ...File::allFiles(resource_path('views/design')),
            ...File::allFiles(app_path('View')),
        ],
    ));
}

it('never hard-codes a company color: brands come from the --brand-* variables', function () {
    $companyColors = [];

    foreach (CompanyCode::cases() as $company) {
        $companyColors = [...$companyColors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (designFiles() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($companyColors) as $color) {
            expect($source)->not->toContain(strtolower($color), "{$path} hard-codes the company color {$color}.");
        }
    }
});

it('writes the interface in sentence case, never in sustained capitals', function () {
    foreach (designFiles() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toMatch('/\buppercase\b/', "{$path} uses the uppercase utility.")
            ->not->toMatch('/text-transform:\s*uppercase/i', "{$path} transforms text to capitals.");
    }
});
