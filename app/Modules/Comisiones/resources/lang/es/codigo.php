<?php

// The settlement of one code (GET /comisiones/periodos/{periodo}/codigos/{codigo}, §7.5).
return [
    'titulo' => 'Código :codigo · :nombre',
    'volver' => 'Volver al consolidado',
    'volver_resumen' => 'Volver a la liquidación',

    'cifras' => [
        'venta' => 'Venta',
        'presupuesto' => 'Presupuesto',
        'sin_presupuesto' => 'Sin presupuesto',
        'cumplimiento' => 'Cumplimiento',
        'cumple' => 'Cumple el mínimo de :minimo',
        'no_cumple' => 'Bajo el mínimo de :minimo',
        'sin_minimo' => 'Sin mínimo',
        'comision_venta' => 'Comisión de venta',
        'recaudo' => 'Recaudo sin IVA',
        'transacciones' => '{1} 1 transacción|[0,*] :cantidad transacciones',
        'comision_recaudo' => 'Comisión de recaudo',
        'total' => 'Total a pagar',
        'total_ayuda' => 'Venta + recaudo + OBR + reintegros − descuentos',
    ],

    'regla' => [
        'titulo' => 'Regla aplicada',
        'tasa_venta' => 'Tasa de venta',
        'tasa_recaudo' => 'Tasa de recaudo',
        'minimo' => 'Mínimo de cumplimiento',
        'alcance' => 'Sección del consolidado',
        'tramos' => 'Tramos por días de pago',
        'sin_tramos' => 'Sin tramos: el recaudo se liquida completo, sin importar los días.',
        'tramo' => ':desde a :hasta días',
        'tramo_abierto' => ':desde días o más',
        'tabla_tramos' => 'Porcentaje de la tasa según los días entre la factura y el pago',
        'columnas' => [
            'dias' => 'Días',
            'porcentaje' => '% de la tasa',
        ],
    ],

    'filtros' => [
        'titulo' => 'Filtrar líneas',
        'etiqueta' => 'Filtros de las líneas del código',
        'cliente' => 'Cliente',
        'cliente_ayuda' => 'Código o nombre',
        'factura' => 'Factura',
        'desde' => 'Desde',
        'hasta' => 'Hasta',
        'aplicar' => 'Filtrar',
        'limpiar' => 'Quitar filtros',
        'nota' => 'Los filtros solo cambian las líneas que ves; las cifras del código son las de la liquidación.',
    ],

    'ventas' => [
        'titulo' => 'Líneas de venta',
        'tabla' => 'Líneas de venta del código',
        'vacio' => 'No hay líneas de venta para mostrar.',
        'mostrando' => 'Muestras :mostradas de :total líneas de venta.',
        'total' => 'Venta del código',
        'columnas' => [
            'documento' => 'Documento',
            'tipo' => 'Tipo',
            'fecha' => 'Fecha',
            'cliente' => 'Cliente',
            'total_sap' => 'Total SAP',
            'venta' => 'Venta',
        ],
    ],

    'recaudos' => [
        'titulo' => 'Líneas de recaudo',
        'tabla' => 'Pagos y cruces del código',
        'vacio' => 'No hay recaudos para mostrar.',
        'mostrando' => 'Muestras :mostradas de :total líneas de recaudo.',
        'total' => 'Total del código',
        'sin_tramo' => 'Completo',
        'columnas' => [
            'factura' => 'Factura',
            'cliente' => 'Cliente',
            'fecha_factura' => 'Fecha factura',
            'fecha_pago' => 'Fecha pago',
            'importe' => 'Importe original',
            'dias' => 'Días',
            'tramo' => '% tramo',
            'pagado' => 'Valor pagado',
            'comision' => 'Comisión',
        ],
    ],

    'capturas' => [
        'obr' => 'Ventas OBR y escudos',
        'tabla_obr' => 'Ventas OBR del código',
        'ajustes' => 'Reintegros y descuentos',
        'tabla_ajustes' => 'Reintegros y descuentos del código',
        'total_obr' => 'Comisión OBR',
        'columnas' => [
            'descripcion' => 'Descripción',
            'vendido' => 'Valor vendido',
            'tasa' => 'Tasa',
            'tipo' => 'Tipo',
            'valor' => 'Valor',
        ],
    ],
];
