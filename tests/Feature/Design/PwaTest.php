<?php

use App\Models\User;
use Tests\Fakes\ContractFakes;
use Tests\Feature\Design\Support\Html;

/**
 * The array literal assigned to a const in public/sw.js, as a PHP list. An
 * item that names another string const (OFFLINE_URL) takes its value.
 *
 * @return list<string>
 */
function serviceWorkerList(string $constant): array
{
    $source = (string) file_get_contents(public_path('sw.js'));
    preg_match("/const {$constant} = \\[(.*?)\\];/s", $source, $match);

    expect($match)->not->toBeEmpty("public/sw.js defines {$constant}.");

    return array_map(function (string $item) use ($source): string {
        $item = trim($item);

        if (preg_match("/^'([^']*)'$/", $item, $literal) === 1) {
            return $literal[1];
        }

        preg_match("/const {$item} = '([^']*)';/", $source, $named);

        return $named[1] ?? $item;
    }, array_values(array_filter(explode(',', $match[1]), fn (string $item): bool => trim($item) !== '')));
}

describe('manifest', function () {
    it('is the only manifest and installs the whole app from the home page', function () {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        expect(glob(public_path('*.webmanifest')))->toHaveCount(1)
            ->and($manifest)->toMatchArray([
                'id' => '/',
                'name' => 'Gestor de cartera',
                'short_name' => 'Cartera',
                'lang' => 'es-CO',
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
            ]);
    });

    it('lists icons that exist with the declared size, including a maskable one', function () {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $sizes = [];

        foreach ($manifest['icons'] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));

            expect($file)->toBeFile();

            if ($icon['type'] === 'image/png') {
                [$width, $height] = getimagesize($file) ?: [0, 0];
                expect("{$width}x{$height}")->toBe($icon['sizes']);
                $sizes[] = $icon['sizes'];
            }
        }

        expect($sizes)->toContain('192x192', '512x512')
            ->and(array_column($manifest['icons'], 'purpose'))->toContain('maskable');
    });
});

describe('service worker', function () {
    it('precaches the offline page and only files that exist', function () {
        $precache = serviceWorkerList('PRECACHE');

        expect($precache)->toContain('/offline.html');

        foreach ($precache as $path) {
            expect(public_path(ltrim($path, '/')))->toBeFile();
        }
    });

    it('caches static folders only, never pages or data', function () {
        expect(serviceWorkerList('STATIC_PREFIXES'))->toEqualCanonicalizing(['/build/', '/fonts/', '/icons/']);

        $source = (string) file_get_contents(public_path('sw.js'));

        // Pages go to the network and fall back to the offline page; nothing else is stored.
        expect($source)->toContain("request.mode === 'navigate'")
            ->toContain('fetch(request).catch(() => caches.match(OFFLINE_URL))')
            ->and(substr_count($source, 'cache.put('))->toBe(1);
    });
});

describe('offline page', function () {
    it('is a self-contained Spanish page that offers to retry', function () {
        $source = (string) file_get_contents(public_path('offline.html'));
        $html = Html::parse($source);

        expect($html->one('//html')->getAttribute('lang'))->toBe('es')
            ->and($html->text('//title'))->toBe('Sin conexión · Gestor de cartera')
            ->and($html->text('//h1'))->toBe('No pudimos abrir esta página')
            ->and($html->text('//button'))->toBe('Reintentar')
            ->and($source)->not->toMatch('#(src|href)="(https?:)?//#');

        foreach ($html->all('//*[@src or @href]') as $element) {
            $path = $element->getAttribute('src') ?: $element->getAttribute('href');
            expect(serviceWorkerList('PRECACHE'))->toContain($path);
        }
    });
});

describe('<head>', function () {
    it('links the one manifest from both layouts', function () {
        ContractFakes::install($this->app);
        $this->actingAs(User::factory()->make());

        foreach (['<x-layouts.app>Contenido</x-layouts.app>', '<x-layouts.guest>Hola</x-layouts.guest>'] as $template) {
            expect(Html::parse((string) $this->blade($template))->one('//link[@rel="manifest"]')->getAttribute('href'))
                ->toBe(asset('manifest.webmanifest'));
        }
    });
});
