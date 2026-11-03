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
    // Matched against the path relative to app/Modules, so the checkout folder never decides.
    $excluded = [
        '/pdf/', '/correos/', '/documentos/', '/alerta/', '/circularizacion/', '/components/correo.blade.php',
        'Core/resources/views/auditoria/alerta.blade.php', 'Core/resources/views/auth/aviso-rol.blade.php',
    ];
    $modules = str_replace('\\', '/', app_path('Modules')).'/';
    $views = [];

    foreach (File::glob(app_path('Modules/*/resources/views')) as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, '.blade.php') && ! Str::contains(Str::after($path, $modules), $excluded)) {
                $views[] = $path;
            }
        }
    }

    return $views;
}

/**
 * Brand v1 tokens that brand v2 retired.
 */
function expectNoRetiredTokens(string $path, string $source): void
{
    // toMatch, not toContain: toContain is variadic and would read the message as a second needle.
    expect($source)->not->toMatch('/brand-primary/', "{$path} uses the retired brand-primary alias; use brand-accent.")
        ->not->toMatch('/fg-soft/', "{$path} uses the retired fg-soft color; use fg-muted, or inherit brand-text.")
        ->not->toMatch('/\bfont-display\b/', "{$path} uses the retired display face.");
}

it('keeps the module screens on the brand v2 tokens, without the retired ones', function () {
    $views = moduleScreenViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        expectNoRetiredTokens($path, $source);
        expect($source)->not->toMatch('/tracking-\[-/', "{$path} tightens the letter spacing.");
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
        expectNoRetiredTokens($path, (string) file_get_contents($path));
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

/**
 * Every HTML e-mail view: it follows the document rules of the brand
 * (references/documentos.md) with inline styles, since mail clients ignore
 * CSS variables.
 *
 * @return list<string>
 */
function mailViews(): array
{
    return [
        app_path('Modules/Cobro/resources/views/components/correo.blade.php'),
        app_path('Modules/Cobro/resources/views/correos/cobro-semanal.blade.php'),
        app_path('Modules/Cobro/resources/views/correos/plantilla-cambiada.blade.php'),
        app_path('Modules/Cobro/resources/views/circularizacion/estado-cartera.blade.php'),
        app_path('Modules/Comisiones/resources/views/correos/aprobacion.blade.php'),
        app_path('Modules/Ingesta/resources/views/alerta/correo.blade.php'),
        app_path('Modules/Core/resources/views/auditoria/alerta.blade.php'),
        app_path('Modules/Core/resources/views/auth/aviso-rol.blade.php'),
    ];
}

it('sets every e-mail in the one Helvetica stack, and in no other face', function () {
    $stack = "Helvetica,Arial,'Helvetica Neue',sans-serif";

    foreach (mailViews() as $path) {
        $source = (string) file_get_contents($path);

        // The weekly and circularization mails take the stack from their frame, <x-cobro::correo>.
        if (! str_contains($source, '<x-cobro::correo ')) {
            // toMatch, not toContain: toContain is variadic and would read the message as a second needle.
            expect($source)->toMatch('/'.preg_quote("font-family:{$stack};", '/').'/', "{$path} does not set the Helvetica stack.");
        }

        preg_match_all('/font-family:([^;"]+)/', $source, $families);

        foreach ($families[1] as $family) {
            expect($family)->toBe($stack, "{$path} sets another face: {$family}.");
        }
    }
});

it('never hard-codes a company color in an e-mail: it comes from the palette', function () {
    $companyColors = [];

    foreach (CompanyCode::cases() as $company) {
        $companyColors = [...$companyColors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (mailViews() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($companyColors) as $color) {
            expect($source)->not->toMatch('/'.preg_quote(strtolower($color), '/').'\b/', "{$path} hard-codes the company color {$color}.");
        }
    }
});

it('adds no image to an e-mail: the logo is separate work', function () {
    foreach (mailViews() as $path) {
        expect((string) file_get_contents($path))->not->toMatch('/<img\b|background-image|url\(/i', "{$path} adds an image.");
    }
});

/**
 * Every PDF view on brand v2: the document rules of the brand
 * (references/documentos.md) in a dompdf style sheet, which reads no CSS
 * variables either.
 *
 * @return list<string>
 */
function pdfViews(): array
{
    return [
        app_path('Modules/Comisiones/resources/views/pdf/consolidado.blade.php'),
        app_path('Modules/Comisiones/resources/views/pdf/recaudo.blade.php'),
        app_path('Modules/Asesores/resources/views/documentos/estado-cuenta.blade.php'),
    ];
}

it('sets every PDF in the one Helvetica stack, in no other face and without images', function () {
    $stack = 'Helvetica, Arial, "Helvetica Neue", sans-serif';

    foreach (pdfViews() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->toMatch('/'.preg_quote("font-family: {$stack};", '/').'/', "{$path} does not set the Helvetica stack.")
            ->not->toMatch('/<img\b|background-image|url\(/i', "{$path} adds an image.");

        preg_match_all('/font-family:\s*([^;]+);/', $source, $families);

        foreach ($families[1] as $family) {
            expect($family)->toBe($stack, "{$path} sets another face: {$family}.");
        }
    }
});

it('never hard-codes a company color in a PDF: it comes from the palette', function () {
    $companyColors = [];

    foreach (CompanyCode::cases() as $company) {
        $companyColors = [...$companyColors, ...array_values((array) BrandPalette::official($company))];
    }

    foreach (pdfViews() as $path) {
        $source = strtolower((string) file_get_contents($path));

        foreach (array_unique($companyColors) as $color) {
            expect($source)->not->toMatch('/'.preg_quote(strtolower($color), '/').'\b/', "{$path} hard-codes the company color {$color}.");
        }
    }
});
