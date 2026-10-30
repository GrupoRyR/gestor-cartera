<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\File;
use Tests\Feature\Design\Support\Html;

/**
 * Rules of brand v2 (skill frontend-marca-sgx-sydc-v2) and Part A.6 checked
 * on the files phase 0b owns, so a later change cannot slip a company color,
 * a display face or an all-caps label into the shared UI.
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

it('sets every component in the one Helvetica stack, without a display face or tight tracking', function () {
    $files = [
        ...File::allFiles(resource_path('views/components/ui')),
        ...File::allFiles(resource_path('views/design')),
        ...File::allFiles(app_path('View')),
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());

        expect($source)->not->toMatch('/\bfont-display\b/', "{$file->getPathname()} uses the display face.")
            ->not->toMatch('/tracking-\[-/', "{$file->getPathname()} tightens the letter spacing.");
    }
});

it('draws component titles in the title color, not the brand text color', function (string $template, string $xpath) {
    $title = Html::parse((string) $this->blade($template))->one($xpath);

    expect(explode(' ', $title->getAttribute('class')))->toContain('text-fg');
})->with([
    'page header' => ['<x-ui.page-header title="Clientes" />', '//h1'],
    'card, level 2' => ['<x-ui.card title="Recaudo">A</x-ui.card>', '//h2'],
    'card, level 4' => ['<x-ui.card title="Detalle" :level="4">A</x-ui.card>', '//h4'],
    'empty state' => ['<x-ui.empty title="Sin compromisos" />', '//*[@data-part="title"]'],
    'modal' => ['<x-ui.modal id="nuevo" title="Nuevo compromiso">A</x-ui.modal>', '//h2'],
]);

it('labels a neutral chip with the brand tint and ink', function () {
    $chip = Html::parse((string) $this->blade('<x-ui.chip :dot="false">SGX</x-ui.chip>'))->one('//*[@data-tone]');

    expect(explode(' ', $chip->getAttribute('class')))->toContain('bg-brand-tint', 'text-brand-ink');
});
