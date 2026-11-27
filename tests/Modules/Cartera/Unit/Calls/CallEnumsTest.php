<?php

use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;

it('names the channels of a call', function () {
    expect(CallChannel::options())->toBe([
        'llamada' => 'Llamada',
        'llamada_whatsapp' => 'Llamada de WhatsApp',
        'mensaje_whatsapp' => 'Mensaje de WhatsApp',
    ]);
});

it('names the outcomes of a call', function () {
    expect(CallOutcome::options())->toBe([
        'contesto' => 'Contestó',
        'no_contesto' => 'No contestó',
        'buzon_voz' => 'Buzón de voz',
        'numero_equivocado' => 'Número equivocado',
        'volver_a_llamar' => 'Pidió volver a llamar',
    ]);
});

it('names the agreements of a call', function () {
    expect(CallAgreement::options())->toBe([
        'ninguno' => 'Ninguno',
        'compromiso_pago' => 'Compromiso de pago',
        'envio_soporte' => 'Envío de soporte',
    ]);
});
