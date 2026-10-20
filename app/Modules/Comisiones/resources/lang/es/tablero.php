<?php

// The dashboard of a period (GET /comisiones, §7.1).
return [
    'titulo' => 'Tablero de comisiones',
    'descripcion' => 'Acumulado del mes según lo que ha llegado de SAP, contra el presupuesto. Es una vista previa: no guarda nada.',

    'periodo' => [
        'etiqueta' => 'Elegir el período',
        'campo' => 'Período',
        'ver' => 'Ver',
    ],

    'kpis' => [
        'liquidacion' => 'Liquidación del período',
        'sin_calcular' => 'Sin calcular',
        'ver_liquidacion' => 'Ver la liquidación',
        'venta' => 'Venta del mes',
        'venta_ayuda' => 'Todos los códigos, con 80 y 100',
        'recaudo' => 'Recaudo del mes',
        'recaudo_ayuda' => 'Pagos y cruces, sin IVA',
        'bloqueantes' => 'Alertas bloqueantes',
        'advertencias' => '{1} 1 advertencia|[0,*] :cantidad advertencias',
        'pendientes' => 'Capturas por aprobar',
        'pendientes_detalle' => ':obr OBR · :ajustes reintegros o descuentos · :cruces cruces manuales · :reasignaciones reasignaciones',
    ],

    'sin_parametros' => 'No hay una tabla de tramos de recaudo vigente para :periodo, así que no podemos mostrar el acumulado. Revisa los parámetros.',

    'avance' => [
        'titulo' => 'Avance contra el presupuesto',
        'descripcion' => 'Cumplimiento de cada código con presupuesto. La marca vertical es su mínimo; desde ahí comisiona la venta.',
        'vacio' => 'Ningún código tiene presupuesto cargado para este período.',
        'eje' => 'Escala de 0 % a :maximo %',
        'cumple' => 'Cumple el mínimo de :minimo',
        'no_cumple' => 'Bajo el mínimo de :minimo',
        'sin_minimo' => 'Sin mínimo',
        'ver_tabla' => 'Ver las cifras en la tabla',
    ],

    'codigos' => [
        'titulo' => 'Acumulado por código',
        'tabla' => 'Venta, presupuesto, cumplimiento y recaudo por código en el período',
        'vacio' => 'Aún no hay ventas, recaudos ni capturas para este período.',
        'sin_presupuesto' => 'Sin presupuesto',
        'columnas' => [
            'codigo' => 'Código',
            'vendedor' => 'Vendedor',
            'venta' => 'Venta',
            'presupuesto' => 'Presupuesto',
            'cumplimiento' => 'Cumplimiento',
            'minimo' => 'Mínimo',
            'comision_venta' => 'Comisión venta',
            'recaudo' => 'Recaudo',
            'comision_recaudo' => 'Comisión recaudo',
        ],
    ],

    'cargas' => [
        'titulo' => 'Cargas de SAP',
        'descripcion' => 'Última carga de cada archivo y las que Ingesta rechazó después.',
        'tabla' => 'Estado de las cargas de SAP',
        'nada' => 'Aún no llegan cargas de SAP para comisiones.',
        'sin_cargas' => 'Sin cargas',
        'sin_errores' => 'Sin errores',
        'rechazadas' => '{1} 1 carga rechazada|[0,*] :cantidad cargas rechazadas',
        'ultima_rechazada' => 'la última el :fecha',
        'filas_detalle' => ':insertadas nuevas · :actualizadas actualizadas',
        'columnas' => [
            'archivo' => 'Archivo',
            'recibida' => 'Última carga',
            'corte' => 'Corte',
            'filas' => 'Filas',
            'errores' => 'Errores',
        ],
    ],
];
