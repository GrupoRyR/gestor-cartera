<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\File;

/**
 * Rules of the ryr-design skill (Part A.6) on the views of this module, which
 * may use the shared components but never add styles or colors of their own.
 *
 * @return list<string>
 */
function asesoresViews(): array
{
    return array_values(array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        File::allFiles(app_path('Modules/Asesores/resources/views')),
    ));
}

it('has views to check', function () {
    expect(asesoresViews())->not->toBeEmpty();
});

it('adds no styles of its own to the screens: no <style> blocks', function () {
    foreach (asesoresViews() as $path) {
        // The PDF documents cannot use the app styles: dompdf needs its CSS in the page.
        if (str_contains($path, DIRECTORY_SEPARATOR.'documentos'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        expect((string) file_get_contents($path))->not->toMatch('/<style\b/i', "{$path} adds a <style> block.");
    }
});

it('writes no raw colors, not even in the documents: they come from the palette of the company', function () {
    foreach (asesoresViews() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toMatch('/#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3})\b/i', "{$path} writes a raw color.")
            ->not->toMatch('/\b(?:rgb|hsl|oklch)a?\(/i', "{$path} writes a raw color function.");
    }
});

it('never hard-codes a company color: brands come from the --brand-* variables', function () {
    $colors = [];

    foreach (CompanyCode::cases() as $company) {
        $colors = [...$colors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (asesoresViews() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($colors) as $color) {
            expect($source)->not->toContain(strtolower($color), "{$path} hard-codes the company color {$color}.");
        }
    }
});

it('writes the interface in sentence case, never in sustained capitals', function () {
    foreach (asesoresViews() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toMatch('/\buppercase\b/', "{$path} uses the uppercase utility.")
            ->not->toMatch('/text-transform:\s*uppercase/i', "{$path} transforms text to capitals.");
    }
});

it('has no sustained capitals in the texts either', function () {
    foreach (File::allFiles(app_path('Modules/Asesores/resources/lang/es')) as $file) {
        $texts = require $file->getPathname();

        array_walk_recursive($texts, function (mixed $text) use ($file): void {
            // Words of 4+ capital letters ("PDF", "NIT" and "SAP" are fine): sustained capitals are whole shouted phrases.
            expect((string) $text)->not->toMatch('/\b\p{Lu}{4,}\b/u', "{$file->getFilename()} shouts: {$text}");
        });
    }
});
