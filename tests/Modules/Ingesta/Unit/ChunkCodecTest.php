<?php

use App\Modules\Ingesta\Infrastructure\Storage\ChunkCodec;

it('round-trips value lists with their exact types', function () {
    $rows = [
        [12, 'ana@segurex.com', 'Ñandú & Cía', '1234567.89', '2026-10-15', null, -3],
        [0, '', '0', '-0.01', '2024-02-29', null, 0],
    ];

    $payload = ChunkCodec::encode($rows);

    expect($payload)->toMatch('/^[A-Za-z0-9+\/]+=*$/')
        ->and(ChunkCodec::decode($payload))->toBe($rows);
});

it('encodes an empty chunk', function () {
    expect(ChunkCodec::decode(ChunkCodec::encode([])))->toBe([]);
});

it('refuses a corrupt payload', function (string $payload) {
    ChunkCodec::decode($payload);
})->with([
    'not base64' => ['%%%'],
    'not gzip' => [base64_encode('plain text')],
    'not json' => [base64_encode((string) gzencode('{oops'))],
    'not a list' => [base64_encode((string) gzencode('{"a":[1]}'))],
    'row not a list' => [base64_encode((string) gzencode('[{"a":1}]'))],
    'nested value' => [base64_encode((string) gzencode('[[1,[2]]]'))],
])->throws(UnexpectedValueException::class);
