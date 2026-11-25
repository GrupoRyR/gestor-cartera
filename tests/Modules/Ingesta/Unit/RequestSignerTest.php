<?php

use App\Modules\Ingesta\Application\Robot\RequestSigner;

it('signs exactly like the robot (shared test vector of etl/sinks/http_sink.py)', function () {
    expect(RequestSigner::sign('k2026a-secreto-de-prueba', 1792152000, '/api/ingesta/cartera', '{"rows":[]}'))
        ->toBe('sha256=ba04577457e09c8e9a6bec8983b85a09559f01b466e48a8f8fb2090e3a5b0fc9');
});

it('builds the canonical string from the timestamp, the method, the path and the body hash', function () {
    expect(RequestSigner::canonical(1792152000, '/api/ingesta/cartera', '{"rows":[]}'))
        ->toBe("1792152000\nPOST\n/api/ingesta/cartera\n".hash('sha256', '{"rows":[]}'));
});

it('builds the path of a batch from its lote, as the robot signs it', function () {
    expect(RequestSigner::batchPath('cartera'))->toBe('/api/ingesta/cartera');
});

it('verifies a signature and refuses any change', function () {
    $signature = RequestSigner::sign('secreto', 1792152000, '/api/ingesta/cartera', 'body');

    expect(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/cartera', 'body', $signature))->toBeTrue()
        ->and(RequestSigner::verify('otro', 1792152000, '/api/ingesta/cartera', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152001, '/api/ingesta/cartera', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/asesores', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/reportes', 'body', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/cartera', 'body!', $signature))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/cartera', 'body', strtoupper($signature)))->toBeFalse()
        ->and(RequestSigner::verify('secreto', 1792152000, '/api/ingesta/cartera', 'body', substr($signature, 7)))->toBeFalse();
});
