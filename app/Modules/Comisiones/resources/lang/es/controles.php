<?php

// The controls of a period (§7.8, §9).
return [
    'titulo_pagina' => 'Controles de :mes',
    'descripcion' => 'Los cuadres y validaciones de la sección 9 sobre la versión que estás viendo.',
    'sin_liquidacion' => 'Calcula la liquidación del período para ver sus controles.',
    'ir_al_periodo' => 'Ir a la liquidación del período',

    'estados' => [
        'cumple' => 'Cumple',
        'no_cumple' => 'No cumple',
        'no_aplica' => 'No aplica',
    ],

    'ventas' => [
        'titulo' => 'Ventas cargadas contra ventas por código',
        'descripcion' => 'El total de ventas cargadas debe ser igual a la suma de las ventas por código, incluidos los códigos 80 y 100.',
        'cargadas' => 'Ventas cargadas',
        'por_codigo' => 'Ventas por código',
        'sin_asignar' => 'Ventas sin código conocido',
    ],

    'recaudos' => [
        'titulo' => 'Recaudos cargados contra recaudos liquidados',
        'descripcion' => 'El total de recaudos cargados debe ser igual al recaudo liquidado, pagos y cruces. Ninguna línea queda fuera por rangos fijos.',
        'cargados' => 'Recaudos cargados',
        'liquidados' => 'Recaudos liquidados',
        'sin_asignar' => 'Recaudos sin código ni vendedor',
    ],

    'informes' => [
        'titulo' => 'Informes PDF contra el consolidado',
        'descripcion' => 'La suma de los totales de los PDF de recaudo debe ser igual a la comisión de recaudo del consolidado.',
        'total_pdf' => 'Suma de los PDF',
        'consolidado' => 'Comisión de recaudo del consolidado',
        'guardados' => ':guardados de :total informes guardados',
        'origen' => 'Calculado con el resultado de la liquidación, el mismo cálculo de los PDF: los informes guardados conservan su huella pero no su total.',
        'no_cuadran' => 'No cuadran: :referencias.',
        'ver_informes' => 'Ver los informes',
    ],

    'control_sap' => [
        'titulo' => 'Archivo de control de SAP',
        'descripcion' => 'Recibimos los datos de SAP por el robot de Ingesta, no por archivos CSV con .ctl, así que no hay archivo de control que comparar. Estas son las cargas del período.',
        'rango' => 'Cargas con corte entre el :desde y el :hasta.',
        'tabla' => 'Cargas de SAP del período por archivo',
        'columnas' => [
            'archivo' => 'Archivo',
            'cargas' => 'Cargas',
            'filas' => 'Filas',
            'detalle' => 'Nuevas · actualizadas',
            'ultima' => 'Última carga',
        ],
    ],

    'duplicados' => [
        'titulo' => 'Duplicados y reprocesos',
        'descripcion' => 'Cada línea se guarda por su llave natural: una recarga actualiza la fila en lugar de duplicarla, así que reprocesar un día es seguro.',
        'tabla' => 'Filas guardadas del período por archivo',
        'columnas' => [
            'archivo' => 'Archivo',
            'filas' => 'Filas del período',
        ],
        'cruces_manuales' => 'Cruces manuales',
        'alertas' => '{0} Ninguna línea duplicada en la liquidación.|{1} 1 línea duplicada en la liquidación.|[2,*] :cantidad líneas duplicadas en la liquidación.',
    ],

    'alertas' => [
        'titulo' => 'Alertas',
        'descripcion' => 'Lo que encontró la liquidación, agrupado por tipo. Las bloqueantes impiden aprobar hasta que se justifiquen.',
        'tabla' => 'Alertas de la liquidación por tipo',
        'vacio' => 'La liquidación no tiene alertas.',
        'columnas' => [
            'tipo' => 'Tipo',
            'severidad' => 'Severidad',
            'cantidad' => 'Cantidad',
        ],
        'sin_fecha_factura' => 'Recaudos sin fecha de factura',
        'sin_fecha_factura_ayuda' => 'Cuentan desde su propia fecha de pago (0 días).',
        'ver_periodo' => 'Ver las alertas en la liquidación',
    ],

    'justificaciones' => [
        'titulo' => 'Alertas bloqueantes justificadas',
        'descripcion' => 'Cada alerta bloqueante necesita una justificación para aprobar la liquidación.',
        'tabla' => 'Alertas bloqueantes y su justificación',
        'vacio' => 'No hay alertas bloqueantes.',
        'sin_justificar' => 'Sin justificar',
        'columnas' => [
            'alerta' => 'Alerta',
            'mensaje' => 'Mensaje',
            'justificacion' => 'Justificación',
        ],
    ],
];
