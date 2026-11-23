<?php

// Apply and reject a receipt (PATCH /soportes/{soporte}/aplicar and
// /rechazar, forms on its detail), read with __('soportes::revision.<key>').
return [

    'descripcion' => 'Aplica el pago en SAP antes de marcarlo como aplicado. Si no se puede aplicar, recházalo con el motivo.',

    'aplicar' => [
        'titulo' => 'Marcar como aplicado',
        'recibo' => 'Número de recibo de caja',
        'recibo_ayuda' => 'Opcional. El número del recibo de caja en SAP.',
        'enviar' => 'Marcar aplicado',
    ],

    'rechazar' => [
        'titulo' => 'Rechazar soporte',
        'motivo' => 'Motivo del rechazo',
        'motivo_ayuda' => 'El asesor lo ve en sus soportes. Hasta 500 caracteres.',
        'motivo_requerido' => 'Escribe el motivo del rechazo: el asesor lo ve.',
        'enviar' => 'Rechazar soporte',
    ],

    'ya_revisado' => 'Este soporte ya fue revisado, así que no se cambió nada.',

    'resultado' => [
        'aplicado' => 'Soporte marcado como aplicado.',
        'rechazado' => 'Soporte rechazado. El asesor verá el motivo.',
    ],

];
