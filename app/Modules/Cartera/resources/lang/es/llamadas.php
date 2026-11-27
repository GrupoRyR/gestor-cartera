<?php

return [
    'canal' => [
        'llamada' => 'Llamada',
        'llamada_whatsapp' => 'Llamada de WhatsApp',
        'mensaje_whatsapp' => 'Mensaje de WhatsApp',
    ],

    'resultado' => [
        'contesto' => 'Contestó',
        'no_contesto' => 'No contestó',
        'buzon_voz' => 'Buzón de voz',
        'numero_equivocado' => 'Número equivocado',
        'volver_a_llamar' => 'Pidió volver a llamar',
    ],

    'acuerdo' => [
        'ninguno' => 'Ninguno',
        'compromiso_pago' => 'Compromiso de pago',
        'envio_soporte' => 'Envío de soporte',
    ],

    'resumen' => [
        'con_contacto' => ':canal a :contacto (:telefono)',
        'sin_contacto' => ':canal al :telefono',
    ],

    'factura' => ':tipo :numero, vence :fecha, saldo :saldo',

    'validacion' => [
        'futura' => 'La llamada no puede tener una fecha futura.',
        'antigua' => 'Solo puedes registrar llamadas de los últimos :dias días.',
        'seguimiento_requerido' => 'Elige el día en que vas a volver a llamar.',
        'seguimiento_pasado' => 'Elige hoy o un día posterior para volver a llamar.',
        'acuerdo_sin_respuesta' => 'Registra un acuerdo solo si el cliente contestó.',
        'facturas_cerradas' => 'Alguna de las facturas ya no está abierta. Vuelve a cargar la ficha.',
    ],
];
