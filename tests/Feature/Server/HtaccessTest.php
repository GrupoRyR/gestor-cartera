<?php

/*
| The tests cannot start Apache, so they read the .htaccess files that GoDaddy
| (cPanel) applies: public/.htaccess, the Document Root, and the root one that
| only answers when the Document Root wrongly points at the repository
| (docs/arquitectura.md §16). Where a rule is a regular expression, the test
| runs it against real paths. The behaviour on the host is checked after each
| deploy with the curl commands of §16.
*/

/**
 * Directive lines (no comments, no blank lines) of a .htaccess file, each
 * with the depth of the <IfModule>/<FilesMatch> blocks that wrap it.
 *
 * @return list<array{line: string, depth: int}>
 */
function htaccessDirectives(string $path): array
{
    $directives = [];
    $depth = 0;

    foreach (preg_split('/\R/', (string) file_get_contents(base_path($path))) ?: [] as $raw) {
        $line = trim($raw);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, '</')) {
            $depth--;

            continue;
        }

        $directives[] = ['line' => $line, 'depth' => $depth];

        if (str_starts_with($line, '<')) {
            $depth++;
        }
    }

    return $directives;
}

/**
 * @return list<string>
 */
function htaccessLines(string $path): array
{
    return array_column(htaccessDirectives($path), 'line');
}

/**
 * Position of the first directive equal to $line, or fails the test.
 */
function htaccessPosition(string $path, string $line): int
{
    $position = array_search($line, htaccessLines($path), true);

    expect($position)->not->toBeFalse("{$path} has no line «{$line}»");

    return (int) $position;
}

/**
 * The rule of public/.htaccess that forbids dotfiles, or fails the test.
 *
 * @return array{line: string, pattern: string}
 */
function dotfileRule(): array
{
    foreach (htaccessLines('public/.htaccess') as $line) {
        if (preg_match('/^RewriteRule\s+(\S*\\\\\.\S*)\s+-\s+\[F,L\]$/', $line, $match) === 1) {
            // mod_rewrite uses PCRE too. In a .htaccess the rule sees the
            // path without its leading slash.
            return ['line' => $line, 'pattern' => '#'.$match[1].'#'];
        }
    }

    throw new RuntimeException('public/.htaccess has no rule that forbids dotfiles.');
}

it('answers 403 to everything when the document root is the repository root', function () {
    expect(file_exists(base_path('.htaccess')))->toBeTrue();

    $directives = htaccessDirectives('.htaccess');
    $lines = array_column($directives, 'line');

    expect($lines)->toContain('<IfModule mod_rewrite.c>', 'RewriteEngine On', 'RewriteRule ^ - [F]');

    // A Require at the top level would be inherited by public/ and take the
    // whole site down; rewrite rules are not inherited.
    $topLevel = array_column(array_filter($directives, fn (array $directive): bool => $directive['depth'] === 0), 'line');

    expect(array_filter($topLevel, fn (string $line): bool => str_starts_with($line, 'Require')))->toBeEmpty();
});

it('keeps its own rewrite engine in public, so the root rule does not apply there', function () {
    expect(htaccessLines('public/.htaccess'))->toContain('RewriteEngine On');
});

it('redirects plain http to https before any other rule', function () {
    $redirect = htaccessPosition('public/.htaccess', 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]');
    $lines = htaccessLines('public/.htaccess');

    expect(array_slice($lines, $redirect - 3, 3))->toBe([
        'RewriteCond %{HTTPS} !=on',
        'RewriteCond %{HTTP:X-Forwarded-Proto} !=https',
        // cPanel AutoSSL validates its certificates over plain http.
        'RewriteCond %{REQUEST_URI} !^/\.well-known/',
    ]);

    expect($redirect)
        ->toBeLessThan(htaccessPosition('public/.htaccess', 'RewriteRule ^ %1 [L,R=301]'))
        ->toBeLessThan(htaccessPosition('public/.htaccess', 'RewriteRule ^ index.php [L]'));
});

it('sends hsts for a year, only over https and only for this host', function () {
    $hsts = array_values(array_filter(
        htaccessLines('public/.htaccess'),
        fn (string $line): bool => str_contains($line, 'Strict-Transport-Security'),
    ));

    expect($hsts)->toHaveCount(1);
    expect(preg_match('/^Header always set Strict-Transport-Security "max-age=(\d+)" env=HTTPS$/', $hsts[0], $match))->toBe(1);
    expect((int) $match[1])->toBeGreaterThanOrEqual(31536000);
});

it('sends the security headers on every response', function (string $header) {
    expect(htaccessLines('public/.htaccess'))->toContain($header);
})->with([
    'nosniff' => 'Header always set X-Content-Type-Options "nosniff"',
    'no framing' => 'Header always set X-Frame-Options "DENY"',
    'referrer' => 'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
    'permissions' => 'Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"',
]);

it('hides the php version', function () {
    expect(htaccessLines('public/.htaccess'))->toContain('Header always unset X-Powered-By', 'Header unset X-Powered-By');
});

it('turns off directory listings even without mod_negotiation', function () {
    $topLevel = array_filter(htaccessDirectives('public/.htaccess'), fn (array $directive): bool => $directive['depth'] === 0);

    expect(array_column($topLevel, 'line'))->toContain('Options -Indexes');
});

it('forbids dotfiles but keeps certificate validation reachable', function (string $path, bool $forbidden) {
    expect(preg_match(dotfileRule()['pattern'], $path))->toBe($forbidden ? 1 : 0);
})->with([
    'env file' => ['.env', true],
    'git folder' => ['.git/config', true],
    'nested dotfile' => ['build/.vite/manifest.json', true],
    'htaccess' => ['.htaccess', true],
    'certificate validation' => ['.well-known/acme-challenge/token', false],
    'built asset' => ['build/assets/app-4f3a.js', false],
    'front controller' => ['index.php', false],
    'route with a dot' => ['comisiones/periodos/2026-09/consolidado.pdf', false],
]);

it('forbids dotfiles before any request reaches laravel', function () {
    expect(htaccessPosition('public/.htaccess', dotfileRule()['line']))
        ->toBeLessThan(htaccessPosition('public/.htaccess', 'RewriteRule ^ index.php [L]'));
});
