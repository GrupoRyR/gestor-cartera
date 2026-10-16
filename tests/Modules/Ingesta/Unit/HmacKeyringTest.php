<?php

use App\Modules\Ingesta\Infrastructure\Robot\HmacKeyring;
use Illuminate\Support\Facades\Log;

it('reads "id:secret" pairs separated by commas', function () {
    $keyring = HmacKeyring::parse(' k2026a : secreto:con:dos-puntos , k2026b:otro ,, ');

    expect($keyring->ids())->toBe(['k2026a', 'k2026b'])
        ->and($keyring->secret('k2026a'))->toBe('secreto:con:dos-puntos')
        ->and($keyring->secret('k2026b'))->toBe('otro')
        ->and($keyring->secret('k2026c'))->toBeNull()
        ->and($keyring->isEmpty())->toBeFalse();
});

it('is empty without keys', function () {
    expect(HmacKeyring::parse('')->isEmpty())->toBeTrue()
        ->and(HmacKeyring::parse(' , ')->isEmpty())->toBeTrue();
});

it('skips malformed pairs with a warning that never shows the secret', function () {
    Log::spy();

    $keyring = HmacKeyring::parse('sin-secreto,:solo-secreto,id con espacio:secreto-1,'.str_repeat('a', 65).':secreto-2,k1:,ok:bien');

    expect($keyring->ids())->toBe(['ok']);

    Log::shouldHaveReceived('warning')->times(5)->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'INGESTA_HMAC_KEYS')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secreto');
    });
});
