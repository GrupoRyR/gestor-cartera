<?php

use App\Modules\Ingesta\Infrastructure\Robot\HmacKeyring;
use Illuminate\Support\Facades\Log;

it('reads "id:secret" pairs separated by commas', function () {
    $keyring = HmacKeyring::parse(' k2026a : secreto:con:dos-puntos-y-32-bytes-o-mas , k2026b:otro-secreto-de-al-menos-32-bytes ,, ');

    expect($keyring->ids())->toBe(['k2026a', 'k2026b'])
        ->and($keyring->secret('k2026a'))->toBe('secreto:con:dos-puntos-y-32-bytes-o-mas')
        ->and($keyring->secret('k2026b'))->toBe('otro-secreto-de-al-menos-32-bytes')
        ->and($keyring->secret('k2026c'))->toBeNull()
        ->and($keyring->isEmpty())->toBeFalse();
});

it('is empty without keys', function () {
    expect(HmacKeyring::parse('')->isEmpty())->toBeTrue()
        ->and(HmacKeyring::parse(' , ')->isEmpty())->toBeTrue();
});

it('skips malformed pairs with a warning that never shows the secret', function () {
    Log::spy();

    $keyring = HmacKeyring::parse('sin-secreto,:solo-secreto,id con espacio:secreto-1,'.str_repeat('a', 65).':secreto-2,k1:,ok:'.str_repeat('b', 32));

    expect($keyring->ids())->toBe(['ok']);

    Log::shouldHaveReceived('warning')->times(5)->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'INGESTA_HMAC_KEYS')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secreto');
    });
});

it('skips a pair whose secret is shorter than 32 bytes with a warning that never shows it', function () {
    Log::spy();

    $keyring = HmacKeyring::parse('corta:'.str_repeat('x', 31).',justa:'.str_repeat('y', 32).',tildes:'.str_repeat('ñ', 16));

    expect($keyring->ids())->toBe(['justa', 'tildes']);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'INGESTA_HMAC_KEYS')
        && str_contains($message, '32 bytes')
        && $context === ['position' => 1, 'key' => 'corta']);
});
