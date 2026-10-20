<?php

// Mail templates: the texts each company edits, and their defaults.
return [

    'titulo' => 'Plantilla del cobro semanal',
    'descripcion' => 'Edita el asunto y los textos del correo de cobro de cada empresa. La tabla de facturas y la marca de la empresa se agregan solas.',
    'empresas' => 'Empresas',

    'campos' => [
        'asunto' => 'Asunto',
        'inicio' => 'Texto antes de la tabla de facturas',
        'cierre' => 'Texto después de la tabla de facturas',
        'ayuda_parrafos' => 'Deja una línea en blanco para separar párrafos.',
    ],

    'variables' => [
        'titulo' => 'Datos que puedes usar',
        'descripcion' => 'Escríbelos entre llaves y se reemplazan en cada correo.',
        'tabla' => 'Datos disponibles en la plantilla',
        'columnas' => [
            'dato' => 'Dato',
            'significa' => 'Qué muestra',
        ],
    ],

    'estado' => [
        'por_defecto' => 'Usa los textos por defecto',
        'editada' => 'Editada por :nombre el :fecha',
    ],

    'acciones' => [
        'guardar' => 'Guardar plantilla',
        'vista_previa' => 'Ver vista previa',
        'restaurar' => 'Volver a los textos por defecto',
    ],

    'mensajes' => [
        'guardada' => 'Guardaste la plantilla de :empresa.',
        'restaurada' => 'La plantilla de :empresa volvió a los textos por defecto.',
        'desconocidas' => 'Estos datos no existen: :variables. Revisa la lista de datos que puedes usar.',
    ],

    'validacion' => [
        'asunto' => 'asunto',
        'inicio' => 'texto antes de la tabla',
        'cierre' => 'texto después de la tabla',
    ],

    // Default texts of each RunKind, with {placeholders}.
    'por_defecto' => [
        'cobro_semanal' => [
            'asunto' => 'Facturas vencidas de {cliente} con {empresa}',
            'inicio' => "Hola, {cliente}:\n\nTe escribimos del área de cartera de {empresa}. Con corte al {fecha_corte}, el saldo vencido de tus facturas es {total_vencido}. Este es el detalle:",
            'cierre' => "Si ya hiciste el pago, responde a este correo con el soporte para aplicarlo. Si tienes alguna duda sobre estas facturas, también puedes responder a este mensaje.\n\nGracias,\nÁrea de cartera de {empresa}",
        ],
        'circularizacion' => [
            'asunto' => 'Estado de cartera de {cliente} con {empresa}',
            'inicio' => "Hola, {cliente}:\n\nCon corte al {fecha_corte}, este es el estado de tu cartera con {empresa}.",
            'cierre' => "Si encuentras alguna diferencia, responde a este correo para revisarla.\n\nGracias,\nÁrea de cartera de {empresa}",
        ],
    ],

];
