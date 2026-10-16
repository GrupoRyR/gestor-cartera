<?php

return [
    'titulo' => 'Tablero de cartera',
    'contexto' => ':empresa · :fecha',
    'descripcion' => 'Cuánto se debe, qué tan atrasado está y quién necesita una llamada hoy.',

    'sin_cartera' => [
        'titulo' => 'Aún no hay cartera cargada',
        'detalle' => 'Cuando llegue la primera carga del robot o un archivo manual, aquí verás los totales.',
    ],

    'indicadores' => [
        'total' => [
            'etiqueta' => 'Cartera total',
            'pista' => '{0} Ningún cliente con saldo|{1} 1 cliente con saldo|[2,*] :count clientes con saldo',
        ],
        'vencido' => [
            'etiqueta' => 'Vencido',
            'pista' => ':porcentaje de la cartera · riesgo :riesgo · :clientes',
            'clientes' => '{0} ningún cliente|{1} 1 cliente|[2,*] :count clientes',
        ],
        'compromisos' => [
            'etiqueta' => 'Compromisos de la semana',
            'pista' => '{0} Ninguno pendiente en los próximos 7 días|[1,*] :valor prometidos para los próximos 7 días',
        ],
        'sin_gestion' => [
            'etiqueta' => 'Vencidos sin gestión',
            'pista' => '{0} Todos tienen gestión reciente|{1} 1 cliente vencido sin gestión en :dias días|[2,*] :count clientes vencidos sin gestión en :dias días',
        ],
    ],

    'riesgo' => [
        'bajo' => 'bajo',
        'medio' => 'medio',
        'alto' => 'alto',
    ],

    'edades' => [
        'titulo' => 'Edades de la cartera vencida',
        'descripcion' => 'Cuánto de lo vencido lleva más tiempo sin pago.',
        'grafico' => 'Distribución de la cartera vencida por edad',
        'tabla' => 'Cartera vencida por edad',
        'vacio' => 'No hay cartera vencida.',
        'bandas' => [
            '1_30' => '1 a 30 días',
            '31_60' => '31 a 60 días',
            '61_90' => '61 a 90 días',
            'mas_90' => 'Más de 90 días',
        ],
        'columnas' => [
            'edad' => 'Edad',
            'valor' => 'Valor',
            'parte' => 'Parte de lo vencido',
        ],
    ],

    'comparacion' => [
        'titulo' => 'SGX frente a SDC',
        'descripcion' => 'La misma cartera, empresa por empresa.',
        'tabla' => 'Comparación de la cartera de cada empresa',
        'metrica' => 'Indicador',
        'filas' => [
            'total' => 'Cartera total',
            'vencido' => 'Vencido',
            'porcentaje' => 'Porcentaje vencido',
            'riesgo' => 'Riesgo',
            'clientes' => 'Clientes con saldo',
            'clientes_vencidos' => 'Clientes vencidos',
        ],
        'riesgo' => 'Riesgo :nivel',
        'composicion' => 'Edad de lo vencido en :empresa',
    ],

    'principales' => [
        'titulo' => 'Los 10 clientes que más deben',
        'descripcion' => 'Ordenados por el valor vencido.',
        'tabla' => 'Clientes con mayor valor vencido',
        'vacio' => [
            'titulo' => 'Sin cartera vencida',
            'detalle' => 'Ningún cliente tiene facturas vencidas en la última carga.',
        ],
        'columnas' => [
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'vencido' => 'Vencido',
            'total' => 'Total',
            'dias' => 'Días de mora',
            'estado' => 'Estado',
            'asesor' => 'Asesor',
        ],
        'sin_asesor' => 'Sin asesor',
    ],

    'semana' => [
        'titulo' => 'Compromisos de la semana',
        'descripcion' => 'Pagos prometidos para hoy y los próximos seis días.',
        'vacio' => [
            'titulo' => 'Sin compromisos esta semana',
            'detalle' => 'Cuando un cliente prometa una fecha de pago, aparecerá aquí.',
        ],
        'vence' => 'Vence',
        'hoy' => 'Hoy',
    ],

    'actividad' => [
        'titulo' => 'Actividad reciente',
        'descripcion' => 'Lo último que se registró en la bitácora de los clientes.',
        'vacio' => [
            'titulo' => 'Sin actividad reciente',
            'detalle' => 'Las llamadas, notas y compromisos que registres aparecerán aquí.',
        ],
        'por' => 'por :usuario',
    ],
];
