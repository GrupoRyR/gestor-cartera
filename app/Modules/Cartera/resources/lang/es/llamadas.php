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

    'mensajes' => [
        'registrada' => 'Llamada registrada.',
        'registrada_compromiso' => 'Llamada registrada. Ahora registra el compromiso de pago.',
    ],

    'compromiso' => [
        'nota' => 'Acordado en la llamada del :fecha.',
    ],

    'formulario' => [
        'boton' => 'Registrar llamada',
        'titulo' => 'Registrar llamada',
        'ocurrio' => 'Fecha y hora',
        'ocurrio_ayuda' => 'Cámbiala si la llamada fue antes; hasta 30 días atrás.',
        'canal' => 'Canal',
        'numero' => 'Número',
        'otro_numero' => 'Otro número',
        'telefono_ayuda' => 'Si escribes un número aquí, se guarda este y no el de la lista.',
        'telefono_solo' => 'Número',
        'persona' => 'Con quién hablaste',
        'otra_persona' => 'Otra persona',
        'contacto_ayuda' => 'Opcional. Si escribes un nombre aquí, se guarda este y no el de la lista.',
        'resultado' => 'Resultado',
        'elige_resultado' => 'Elige un resultado',
        'notas' => 'Notas',
        'notas_ayuda' => 'Qué dijo el cliente y los próximos pasos.',
        'acuerdo' => 'Acuerdo',
        'acuerdo_ayuda' => 'Solo si el cliente contestó.',
        'acuerdo_ayuda_compromiso' => 'Solo si el cliente contestó. El valor y la fecha del compromiso de pago los registras aparte, en «Nuevo compromiso de pago».',
        'seguimiento' => 'Volver a llamar el',
        'seguimiento_ayuda' => 'Obligatorio si el resultado es «Pidió volver a llamar».',
        'facturas' => 'Facturas de las que hablaron',
        'facturas_ayuda' => 'Opcional. Marca las que tocaron en la llamada.',
        'sin_facturas' => 'El cliente no tiene facturas abiertas.',
        'cancelar' => 'Cancelar',
        'guardar' => 'Guardar llamada',
    ],

    'validacion' => [
        'futura' => 'La llamada no puede tener una fecha futura.',
        'antigua' => 'Solo puedes registrar llamadas de los últimos :dias días.',
        'seguimiento_requerido' => 'Elige el día en que vas a volver a llamar.',
        'seguimiento_pasado' => 'Elige hoy o un día posterior para volver a llamar.',
        'acuerdo_sin_respuesta' => 'Registra un acuerdo solo si el cliente contestó.',
        'facturas_cerradas' => 'Alguna de las facturas ya no está abierta. Vuelve a cargar la ficha.',
    ],

    'aviso_seguimiento' => [
        'titulo' => ':cliente: hoy toca volver a llamar',
        'cuerpo' => 'Lo acordaste en la llamada del :fecha.',
        'cuerpo_con_contacto' => 'Lo acordaste en la llamada del :fecha con :contacto.',
    ],

    'consola' => [
        'enviados' => '{0} No hay seguimientos para avisar.|{1} Avisamos 1 seguimiento en la campana.|[2,*] Avisamos :total seguimientos en la campana.',
        'omitidos' => '{1} Omitimos 1 seguimiento; el motivo quedó en el log.|[2,*] Omitimos :total seguimientos; los motivos quedaron en el log.',
        'fallidos' => '{1} No pudimos avisar 1 seguimiento; el error quedó en el log.|[2,*] No pudimos avisar :total seguimientos; los errores quedaron en el log.',
    ],
];
