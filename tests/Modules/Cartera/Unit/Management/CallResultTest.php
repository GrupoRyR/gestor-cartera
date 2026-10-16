<?php

use App\Modules\Cartera\Domain\Management\CallResult;

it('keeps the call results of the Phase 1 app, in the same order and words', function () {
    expect(array_map(fn (CallResult $result): string => $result->label(), CallResult::cases()))->toBe([
        'Contactado — promete pago',
        'Contactado — disputa factura',
        'Contactado — sin acuerdo',
        'No contesta',
        'Número errado',
        'Buzón de voz',
    ]);
});

it('offers value => label pairs for a select', function () {
    expect(CallResult::options())->toBe([
        'promete_pago' => 'Contactado — promete pago',
        'disputa_factura' => 'Contactado — disputa factura',
        'sin_acuerdo' => 'Contactado — sin acuerdo',
        'no_contesta' => 'No contesta',
        'numero_errado' => 'Número errado',
        'buzon_voz' => 'Buzón de voz',
    ]);
});
