<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

/**
 * Screen views of every module. Mail, PDF and document views are left out:
 * they follow the document rules of the brand, not the screen tokens.
 *
 * @return list<string>
 */
function moduleScreenViews(): array
{
    $excluded = ['/pdf/', '/correos/', '/documentos/', '/alerta/', '/circularizacion/', '/components/correo.blade.php'];
    $views = [];

    foreach (File::glob(app_path('Modules/*/resources/views')) as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, '.blade.php') && ! Str::contains($path, $excluded)) {
                $views[] = $path;
            }
        }
    }

    return $views;
}

it('keeps the module screens on the brand v2 tokens, without the retired ones', function () {
    $views = moduleScreenViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        // toMatch, not toContain: toContain is variadic and would read the message as a second needle.
        expect($source)->not->toMatch('/brand-primary/', "{$path} uses the retired brand-primary alias; use brand-accent.")
            ->not->toMatch('/fg-soft/', "{$path} uses the retired fg-soft color; use fg-muted, or inherit brand-text.")
            ->not->toMatch('/\bfont-display\b/', "{$path} uses the display face.")
            ->not->toMatch('/tracking-\[-/', "{$path} tightens the letter spacing.");
    }
});

it('never hard-codes a company color: brands come from the --brand-* variables', function () {
    $companyColors = [];

    foreach (CompanyCode::cases() as $company) {
        $companyColors = [...$companyColors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (designFiles() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($companyColors) as $color) {
            expect($source)->not->toMatch('/'.preg_quote(strtolower($color), '/').'/', "{$path} hard-codes the company color {$color}.");
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

it('no longer defines or uses the retired brand-primary, fg-soft and display font tokens', function () {
    foreach (designFiles() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toMatch('/brand-primary/', "{$path} uses the retired brand-primary alias; use brand-accent.")
            ->not->toMatch('/fg-soft/', "{$path} uses the retired fg-soft color; use fg-muted, or inherit brand-text.")
            ->not->toMatch('/\bfont-display\b/', "{$path} uses the retired display font token.");
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

it('keeps a neutral chip gray, so a company code never wears the active brand', function () {
    $chip = Html::parse((string) $this->blade('<x-ui.chip :dot="false">SDC</x-ui.chip>'))->one('//*[@data-tone]');

    expect(explode(' ', $chip->getAttribute('class')))->toContain('bg-status-neutral-bg', 'text-status-neutral')
        ->not->toContain('bg-brand-tint');
});
