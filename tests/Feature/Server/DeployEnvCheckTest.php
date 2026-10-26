<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
| deploy/check-env.sh reads the server's .env before deploy/deploy.sh touches
| anything (audit S-30). The tests run it with bash against temporary .env
| files; it only reads the file it gets.
*/

const GOOD_PRODUCTION_ENV = [
    'APP_NAME="Gestor de cartera"',
    'APP_ENV=production',
    'APP_DEBUG=false',
    'APP_URL=https://cartera.segurex.com',
    'SESSION_SECURE_COOKIE=true',
    'SESSION_ENCRYPT=true',
];

/**
 * The bash that runs the deploy scripts: on Windows, the one Git for Windows
 * ships next to git (it is not on PowerShell's PATH); elsewhere, bash on PATH.
 */
function bashBinary(): ?string
{
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' && ($git = $finder->find('git')) !== null) {
        // <Git>/cmd/git.exe → <Git>/bin/bash.exe
        $bash = dirname($git, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'bash.exe';

        if (is_file($bash)) {
            return $bash;
        }
    }

    return $finder->find('bash');
}

/**
 * Runs deploy/check-env.sh with $file as its argument. The checked variables
 * are removed from its environment (phpunit sets APP_ENV=testing), except
 * the ones in $environment.
 *
 * @param  array<string, string>  $environment
 */
function runCheckEnv(string $file, array $environment = []): ProcessResult
{
    $bash = bashBinary() ?? test()->markTestSkipped('bash is not installed; CI runs this test on Linux.');

    $unset = array_fill_keys(['APP_ENV', 'APP_DEBUG', 'APP_URL', 'SESSION_SECURE_COOKIE', 'SESSION_ENCRYPT'], false);

    return Process::env([...$unset, ...$environment])
        ->run([$bash, base_path('deploy/check-env.sh'), str_replace('\\', '/', $file)]);
}

/**
 * Runs deploy/check-env.sh on a temporary .env with $lines.
 *
 * @param  list<string>  $lines
 * @param  array<string, string>  $environment
 */
function checkEnv(array $lines, array $environment = []): ProcessResult
{
    $file = (string) tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, implode("\n", $lines)."\n");

    try {
        return runCheckEnv($file, $environment);
    } finally {
        unlink($file);
    }
}

/**
 * GOOD_PRODUCTION_ENV with $name set to $value, or removed when null.
 *
 * @return list<string>
 */
function productionEnvWith(string $name, ?string $value): array
{
    $lines = array_values(array_filter(GOOD_PRODUCTION_ENV, fn (string $line): bool => ! str_starts_with($line, "{$name}=")));

    return $value === null ? $lines : [...$lines, "{$name}={$value}"];
}

it('accepts a production .env', function () {
    $result = checkEnv(GOOD_PRODUCTION_ENV);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});

it('accepts a .env that leaves the safe defaults unset', function (string $name) {
    expect(checkEnv(productionEnvWith($name, null))->exitCode())->toBe(0);
})->with(['APP_ENV', 'APP_DEBUG', 'SESSION_SECURE_COOKIE', 'SESSION_ENCRYPT']);

it('accepts quoted values and comments', function () {
    $result = checkEnv([
        '# Production',
        'APP_ENV="production"',
        "APP_DEBUG='false'",
        'APP_URL="https://cartera.segurex.com"',
        'SESSION_SECURE_COOKIE=true # required',
        '',
    ]);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});

it('rejects an unsafe value and names it', function (string $name, ?string $value) {
    $result = checkEnv(productionEnvWith($name, $value));

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain($name);
})->with([
    'local environment' => ['APP_ENV', 'local'],
    'staging environment' => ['APP_ENV', 'staging'],
    'debug on' => ['APP_DEBUG', 'true'],
    'debug as 1' => ['APP_DEBUG', '1'],
    'plain http url' => ['APP_URL', 'http://cartera.segurex.com'],
    'missing url' => ['APP_URL', null],
    'cookie not secure' => ['SESSION_SECURE_COOKIE', 'false'],
    // Laravel's env() returns '' for a blank value, and '' is falsy.
    'cookie blank' => ['SESSION_SECURE_COOKIE', ''],
    'session not encrypted' => ['SESSION_ENCRYPT', 'false'],
    'session encryption blank' => ['SESSION_ENCRYPT', ''],
]);

it('rejects a checked variable set in the server environment, because it wins over .env', function () {
    $result = checkEnv(GOOD_PRODUCTION_ENV, ['APP_DEBUG' => 'true']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('APP_DEBUG is set in the server environment');
});

it('uses the first definition of a variable, as phpdotenv does', function () {
    $result = checkEnv([...GOOD_PRODUCTION_ENV, 'APP_DEBUG=true']);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});

it('fails when the .env file does not exist', function () {
    $result = runCheckEnv('no-existe/.env');

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('no-existe/.env');
});

it('runs before deploy.sh enters maintenance mode', function () {
    $script = (string) file_get_contents(base_path('deploy/deploy.sh'));

    expect(strpos($script, 'deploy/check-env.sh'))->not->toBeFalse()
        ->and(strpos($script, 'deploy/check-env.sh'))->toBeLessThan((int) strpos($script, 'artisan down'))
        ->and($script)->toContain('chmod 600 .env')
        ->not->toContain('storage:link');
});
