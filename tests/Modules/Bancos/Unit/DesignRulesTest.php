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
function bancosViews(): array
{
    return array_values(array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        File::allFiles(app_path('Modules/Bancos/resources/views')),
    ));
}

it('has views to check', function () {
    expect(bancosViews())->not->toBeEmpty();
});

it('adds no styles of its own: no <style> blocks', function () {
    foreach (bancosViews() as $path) {
        $this->assertDoesNotMatchRegularExpression('/<style\b/i', (string) file_get_contents($path), "{$path} adds a <style> block.");
    }
});

it('writes no raw colors: they come from the palette of the company', function () {
    foreach (bancosViews() as $path) {
        $source = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression('/#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3})\b/i', $source, "{$path} writes a raw color.");
        $this->assertDoesNotMatchRegularExpression('/\b(?:rgb|hsl|oklch)a?\(/i', $source, "{$path} writes a raw color function.");
    }
});

it('never hard-codes a company color: brands come from the --brand-* variables', function () {
    $colors = [];

    foreach (CompanyCode::cases() as $company) {
        $colors = [...$colors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (bancosViews() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($colors) as $color) {
            $this->assertStringNotContainsString(strtolower($color), $source, "{$path} hard-codes the company color {$color}.");
        }
    }
});

it('only sets inline styles for the width of a bar', function () {
    foreach (bancosViews() as $path) {
        preg_match_all('/style="([^"]*)"/', (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $style) {
            $this->assertMatchesRegularExpression('/^width: \{\{ [^}]+ \}\}%$/', $style, "{$path} has an inline style that is not a bar width: {$style}");
        }
    }
});

it('writes the text of the interface in sentence case: nothing in sustained capitals', function () {
    $texts = [];

    foreach (['cargas', 'movimientos', 'recaudo'] as $file) {
        $lang = require app_path("Modules/Bancos/resources/lang/es/{$file}.php");

        array_walk_recursive($lang, function (mixed $text) use (&$texts): void {
            $texts[] = (string) $text;
        });
    }

    foreach ($texts as $text) {
        // Three or more capitals in a row, apart from the codes of the companies and the systems (SGX, SDC, SAP, COP, USD).
        $without = (string) preg_replace('/\b(?:SGX|SDC|SAP|COP|USD)\b/', '', $text);

        $this->assertDoesNotMatchRegularExpression('/\p{Lu}{3,}/u', $without, "«{$text}» is in sustained capitals.");
    }
});
