<?php

use App\Modules\Ingesta\Application\Robot\RequestSigner;

it('signs exactly like the robot (shared test vector of etl/sinks/http_sink.py)', function () {
    expect(RequestSigner::sign('k2026a-secreto-de-prueba', 1792152000, 'cartera', '{"rows":[]}'))
        ->toBe('sha256=ba04577457e09c8e9a6bec8983b85a09559f01b466e48a8f8fb2090e3a5b0fc9');
});

it('builds the canonical string from the timestamp, the method, the path and the body hash', function () {
    expect(RequestSigner::canonical(1792152000, 'cartera', '{"rows":[]}'))
        ->toBe("1792152000\nPOST\n/api/ingesta/cartera\n".hash('sha256', '{"rows":[]}'));
});

it('verifies a signature and refuses any change', function () {
    $signature = RequestSigner::sign('secreto', 1792152000, 'cartera', 'body');

    expect(RequestSigner::verify('secreto', 1792152000, 'cartera', 'body', $signature))->toBeTrue()
        ->and(RequestSigner::verify('otro', 1792152000, 'cartera', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152001, 'cartera', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, 'asesores', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, 'cartera', 'body!', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, 'cartera', 'body', strtoupper($signature)))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, 'cartera', 'body', substr($signature, 7)))->toBeFalse();
});
