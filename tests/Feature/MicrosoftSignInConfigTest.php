<?php

use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/**
 * Sign-in settings of the Microsoft driver with $tenant as the tenant.
 */
function microsoftSignInConfig(?string $tenant): void
{
    config(['services.microsoft' => [
        'client_id' => 'client-id-de-prueba',
        'client_secret' => 'secreto-de-prueba',
        'redirect' => 'https://cartera.example/auth/microsoft/callback',
        'tenant' => $tenant,
    ]]);
}

/**
 * Runs $callback on an application booted with MICROSOFT_TENANT_ID empty,
 * whatever the local .env says, and restores the environment afterwards.
 */
function withBlankTenantEnvironment(Closure $callback): void
{
    $previous = [
        'server' => $_SERVER['MICROSOFT_TENANT_ID'] ?? null,
        'env' => $_ENV['MICROSOFT_TENANT_ID'] ?? null,
        'getenv' => getenv('MICROSOFT_TENANT_ID'),
    ];

    $_SERVER['MICROSOFT_TENANT_ID'] = $_ENV['MICROSOFT_TENANT_ID'] = '';
    putenv('MICROSOFT_TENANT_ID=');

    try {
        $callback();
    } finally {
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($previous[$key] === null) {
                unset($GLOBALS[$global]['MICROSOFT_TENANT_ID']);
            } else {
                $GLOBALS[$global]['MICROSOFT_TENANT_ID'] = $previous[$key];
            }
        }

        putenv($previous['getenv'] === false ? 'MICROSOFT_TENANT_ID' : 'MICROSOFT_TENANT_ID='.$previous['getenv']);
    }
}

it('refuses to build the Microsoft driver without a tenant', function (?string $tenant) {
    microsoftSignInConfig($tenant);

    expect(fn () => Socialite::driver('microsoft'))
        ->toThrow(RuntimeException::class, 'MICROSOFT_TENANT_ID is required');
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
]);

it('builds the Microsoft driver for the configured tenant', function () {
    microsoftSignInConfig('00000000-0000-0000-0000-000000000000');

    $driver = Socialite::driver('microsoft');

    expect($driver)->toBeInstanceOf(MicrosoftProvider::class)
        ->and($driver->getLogoutUrl())
        ->toStartWith('https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/');
});

it('still boots and answers the health check without a tenant', function () {
    withBlankTenantEnvironment(function () {
        $this->refreshApplication();

        expect(config('services.microsoft.tenant'))->toBeIn([null, '']);

        $this->get('/up')->assertOk();
    });
});
