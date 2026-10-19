<?php

use App\Modules\Ingesta\Application\ErrorKeys;

it('puts the errors back in the order the validator reported them', function () {
    // MySQL's JSON type stores object keys sorted by length, so they come
    // back in this order instead of the one the validator used.
    $stored = [
        'rows' => ['Sin filas.'],
        'rows.3' => ['Faltan columnas.'],
        'rows.10' => ['Llave repetida.'],
        'batch_id' => ['Muy largo.'],
        'rows.3.dias' => ['No es entero.'],
        'rows.3.saldo' => ['No es decimal.'],
        'cutoff_date' => ['Futura.'],
        'rows.10.codigo_sn' => ['Requerida.'],
    ];

    expect(array_keys(ErrorKeys::sort($stored, ['codigo_sn', 'dias', 'saldo'])))->toBe([
        'batch_id',
        'cutoff_date',
        'rows',
        'rows.3',
        'rows.3.dias',
        'rows.3.saldo',
        'rows.10',
        'rows.10.codigo_sn',
    ]);
});

it('keeps the messages of every key and orders unknown keys and columns by name', function () {
    $sorted = ErrorKeys::sort([
        'rows.1.zeta' => ['z'],
        'otro' => ['o'],
        'rows.1.alfa' => ['a', 'b'],
        'body' => ['c'],
    ], []);

    expect($sorted)->toBe([
        'body' => ['c'],
        'otro' => ['o'],
        'rows.1.alfa' => ['a', 'b'],
        'rows.1.zeta' => ['z'],
    ]);
});
