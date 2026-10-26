<?php

use Illuminate\Support\Facades\Route;

/*
| Production defaults of the base configuration (audit S-02, S-21, S-23 and
| S-27). The session settings are read again from config/session.php with a
| controlled environment, because CI copies .env.example to .env and that file
| may set the same variables.
*/

/**
 * config/session.php evaluated with $variables set (a null value unsets the
 * variable), restoring the environment afterwards.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function sessionConfigWith(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        setTestEnvironmentVariable($name, $value);
    }

    try {
        return require base_path('config/session.php');
    } finally {
        foreach ($previous as $name => [$server, $env, $getenv]) {
            setTestEnvironmentVariable($name, null);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($getenv !== false) {
                putenv("{$name}={$getenv}");
            }
        }
    }
}

function setTestEnvironmentVariable(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }

    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

it('marks the session cookie secure by default when the app runs on https', function (string $url, bool $secure) {
    $config = sessionConfigWith(['APP_URL' => $url, 'SESSION_SECURE_COOKIE' => null]);

    expect($config['secure'])->toBe($secure);
})->with([
    'https' => ['https://cartera.segurex.com', true],
    'http in local' => ['http://localhost', false],
]);

it('lets an explicit SESSION_SECURE_COOKIE win, so deploy/check-env.sh must catch a false one', function () {
    $config = sessionConfigWith(['APP_URL' => 'https://cartera.segurex.com', 'SESSION_SECURE_COOKIE' => 'false']);

    expect($config['secure'])->toBeFalse();
});

it('encrypts the session by default', function () {
    $config = sessionConfigWith(['SESSION_ENCRYPT' => null]);

    expect($config['encrypt'])->toBeTrue();
});

it('answers no cross-origin request to the api', function (string $method) {
    $response = $this->call($method, '/api/recaudo', server: [
        'HTTP_ORIGIN' => 'https://otro-sitio.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with(['preflight' => 'OPTIONS', 'request' => 'GET']);

it('does not serve the private disk over /storage', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();
});

it('links no public storage folder, because no module uses the public disk', function () {
    expect(config('filesystems.links'))->toBe([]);
});
