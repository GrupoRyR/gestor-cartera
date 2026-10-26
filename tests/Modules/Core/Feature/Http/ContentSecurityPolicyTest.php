<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\Finder\Finder;

/*
| Content-Security-Policy of every web page (audit S-06). The nonce is the one
| Vite puts on its script tags and the one inline scripts must carry, so the
| test route answers it for the test to compare with the header.
*/

beforeEach(function () {
    Route::middleware('web')->get('/_test/nonce', fn () => (string) Vite::cspNonce());
});

/**
 * The directives of a Content-Security-Policy header, by name.
 *
 * @return array<string, list<string>>
 */
function cspDirectives(string $header): array
{
    $directives = [];

    foreach (array_filter(array_map(trim(...), explode(';', $header))) as $directive) {
        $parts = preg_split('/\s+/', $directive) ?: [];
        $directives[(string) array_shift($parts)] = $parts;
    }

    return $directives;
}

it('sends a content security policy with the nonce the page uses', function () {
    $response = $this->get('/_test/nonce')->assertOk();
    $nonce = $response->getContent();

    expect($nonce)->not->toBe('')
        ->and(cspDirectives((string) $response->headers->get('Content-Security-Policy'))['script-src'])
        ->toBe(["'self'", "'nonce-{$nonce}'", "'unsafe-eval'"]);
});

it('uses a new nonce on every request', function () {
    expect($this->get('/_test/nonce')->getContent())->not->toBe($this->get('/_test/nonce')->getContent());
});

it('allows only the app itself to load resources, frame the page or receive forms', function () {
    $directives = cspDirectives((string) $this->get('/_test/nonce')->headers->get('Content-Security-Policy'));

    expect($directives)->toMatchArray([
        'default-src' => ["'self'"],
        // Inline style attributes and the brand <style> of the layout.
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'worker-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ]);
});

it('protects every response, including redirects and error pages', function (string $uri, int $status) {
    // The router sorts auth ahead of the "web" group, so the guest redirect
    // only gets the header from global middleware.
    $this->get($uri)
        ->assertStatus($status)
        ->assertHeader('Content-Security-Policy');
})->with([
    'core page' => ['/login', 200],
    'module page, guest redirect' => ['/cartera', 302],
    'another module' => ['/comisiones', 302],
    'unknown route' => ['/no-existe', 404],
]);

it('sends no policy in local, where the Vite dev server serves the scripts', function () {
    app()['env'] = 'local';

    $this->get('/_test/nonce')->assertHeaderMissing('Content-Security-Policy');
});

it('puts the nonce on every inline script of every view', function () {
    $views = Finder::create()->files()->name('*.blade.php')->in([
        resource_path('views'),
        ...glob(app_path('Modules/*/resources/views'), GLOB_ONLYDIR) ?: [],
    ]);

    $missing = [];

    foreach ($views as $view) {
        preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $view->getContents(), $tags);

        foreach ($tags[0] as $tag) {
            if (! str_contains($tag, 'nonce="{{ Vite::cspNonce() }}"')) {
                $missing[] = $view->getRelativePathname().': '.$tag;
            }
        }
    }

    expect($missing)->toBe([]);
});
