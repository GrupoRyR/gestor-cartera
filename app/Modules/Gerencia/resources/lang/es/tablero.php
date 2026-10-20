<?php

return [
    'titulo' => 'Tablero gerencial',
    'contexto' => ':empresa · :periodo',
    'descripcion' => 'Cartera, cumplimiento de compromisos, resultados del cobro y recaudo del grupo en un solo lugar.',

    'periodos' => [
        'etiqueta' => 'Período del tablero',
        'mes' => 'Este mes',
        'mes_anterior' => 'Mes anterior',
        'trimestre' => 'Trimestre móvil',
        'anio' => 'Este año',
        'fechas' => ':desde al :hasta',
    ],

    'meses_cortos' => ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
    'meses_largos' => ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],

    'sin_cartera' => [
        'titulo' => 'Aún no hay cartera cargada',
        'detalle' => 'Cuando llegue la primera carga del robot o un archivo manual, aquí verás los indicadores del grupo.',
    ],

    'indicadores' => [
        'total' => [
            'etiqueta' => 'Cartera total',
            'pista' => '{0} Ningún cliente con saldo|{1} 1 cliente con saldo|[2,*] :count clientes con saldo',
        ],
        'vencido' => [
            'etiqueta' => 'Cartera vencida',
            'pista' => ':porcentaje de la cartera · :clientes',
            'clientes' => '{0} ningún cliente vencido|{1} 1 cliente vencido|[2,*] :count clientes vencidos',
        ],
        'rotacion' => [
            'etiqueta' => 'Rotación (aprox., por recaudo)',
            'valor' => ':veces veces',
            'pista' => 'Es aproximada: recaudo bancario del período ÷ cartera promedio, no ventas.',
            'dias' => 'Una vuelta cada :dias días.',
            'sin_vuelta' => 'No hubo recaudo en el período.',
        ],
        'cumplimiento' => [
            'etiqueta' => 'Cumplimiento de compromisos',
            'pista' => ':cumplidos de :evaluados compromisos cumplidos',
            'sin_datos' => 'Ningún compromiso vencido en el período.',
        ],
    ],

    'comparacion' => [
        'titulo' => 'Grupo frente a cada empresa',
        'descripcion' => 'Las mismas cifras del período, empresa por empresa.',
        'tabla' => 'Comparación del grupo y de cada empresa',
        'metrica' => 'Indicador',
        'filas' => [
            'total' => 'Cartera total',
            'vencido' => 'Cartera vencida',
            'porcentaje' => 'Porcentaje vencido',
            'clientes' => 'Clientes con saldo',
            'clientes_vencidos' => 'Clientes vencidos',
            'recaudo' => 'Recaudo del período',
            'rotacion' => 'Rotación (aprox., por recaudo)',
            'dias' => 'Días por vuelta (aprox.)',
        ],
    ],

    'tendencia' => [
        'titulo' => 'Cartera vencida mes a mes',
        'descripcion' => 'Porcentaje de la cartera que estaba vencido al cierre de cada mes.',
        'grafico' => 'Porcentaje de cartera vencida por mes',
        'tabla_resumen' => 'Ver los datos en una tabla',
        'tabla' => 'Cartera vencida mes a mes',
        'vacio' => [
            'titulo' => 'Todavía no hay historia',
            'detalle' => 'El gráfico aparece cuando haya al menos dos cierres mensuales cargados.',
        ],
        'columnas' => [
            'mes' => 'Mes',
            'total' => 'Cartera total',
            'vencido' => 'Cartera vencida',
            'porcentaje' => '% vencido',
            'porcentaje_empresa' => '% vencido · :empresa',
        ],
    ],

    'cumplimiento' => [
        'titulo' => 'Cumplimiento de compromisos por asesor',
        'descripcion' => 'Compromisos de pago que vencieron en el período; los más incumplidos van primero.',
        'tabla' => 'Cumplimiento de compromisos por asesor',
        'sin_asesor' => 'Sin asesor',
        'parcial' => [
            'titulo' => 'Cifras parciales',
            'detalle' => 'Hay más compromisos de los que se pueden leer de una vez; estas cifras cubren solo una parte del período.',
        ],
        'vacio' => [
            'titulo' => 'Sin compromisos en el período',
            'detalle' => 'Ningún compromiso de pago vence entre estas fechas.',
        ],
        'columnas' => [
            'asesor' => 'Asesor',
            'empresa' => 'Empresa',
            'evaluados' => 'Evaluados',
            'cumplidos' => 'Cumplidos',
            'a_tiempo' => 'A tiempo',
            'incumplidos' => 'Incumplidos',
            'vencidos' => 'Vencidos sin pago',
            'proximos' => 'Próximos',
            'valor' => 'Valor cumplido / evaluado',
            'cumplimiento' => 'Cumplimiento',
        ],
    ],

    'cobro' => [
        'titulo' => 'Resultados del cobro por correo',
        'descripcion' => 'Lotes programados en el período, por tipo y por empresa. Cada cifra cuenta clientes; las respuestas cuentan aunque lleguen después.',
        'tabla' => ':tipo por empresa',
        'vacio' => [
            'titulo' => 'Sin envíos en el período',
            'detalle' => 'No se programó ningún lote de este tipo entre estas fechas.',
        ],
        'enviados' => [
            'etiqueta' => 'Enviados',
            'pista' => '{1} Clientes en 1 lote|[2,*] Clientes en :count lotes',
        ],
        'entregados' => [
            'etiqueta' => 'Entregados',
            'pista' => 'Enviados que no rebotaron.',
        ],
        'rebotes' => [
            'etiqueta' => 'Rebotados',
            'pista' => ':porcentaje de lo enviado',
        ],
        'respuestas' => [
            'etiqueta' => 'Respuestas',
            'pista' => ':porcentaje de lo enviado',
        ],
        'sin_correo' => [
            'etiqueta' => 'Sin correo',
            'pista' => 'Clientes sin correo registrado; no se les envió.',
        ],
        'fallidos' => [
            'etiqueta' => 'Fallidos',
            'pista' => 'El buzón no tomó el correo.',
        ],
        'sin_leer' => [
            'etiqueta' => 'Sin leer ahora',
            'pista' => 'Respuestas y rebotes por revisar, de cualquier fecha.',
        ],
        'nada_enviado' => 'No salió ningún correo',
        'columnas' => [
            'empresa' => 'Empresa',
            'lotes' => 'Lotes',
            'enviados' => 'Enviados',
            'entregados' => 'Entregados',
            'rebotados' => 'Rebotados',
            'tasa_rebote' => '% rebotado',
            'respuestas' => 'Respuestas',
            'tasa_respuesta' => '% respuesta',
            'sin_correo' => 'Sin correo',
            'fallidos' => 'Fallidos',
        ],
        'enlaces' => [
            'envios' => 'Ver los informes de envío',
            'respuestas' => 'Ver las respuestas',
        ],
    ],

    'recaudo' => [
        'titulo' => 'Recaudo',
        'descripcion' => 'Lo que entró a los bancos, :granularidad.',
        'por_dia' => 'día por día',
        'por_semana' => 'semana por semana',
        'grafico' => 'Recaudo bancario :granularidad',
        'total' => [
            'etiqueta' => 'Recaudo del período',
            'pista' => 'Los movimientos bancarios llegan al día siguiente.',
        ],
        'semanal' => [
            'titulo' => 'Recaudo por semana',
            'tabla' => 'Recaudo por semana',
            'columnas' => [
                'semana' => 'Semana',
                'empresa' => 'Empresa',
                'total' => 'Recaudo',
            ],
            'rango' => ':desde al :hasta',
            'titulo_barra' => 'Semana del :desde al :hasta',
        ],
        'diario' => [
            'resumen' => 'Ver el recaudo día por día en una tabla',
            'tabla' => 'Recaudo día por día',
            'columnas' => [
                'fecha' => 'Fecha',
                'total' => 'Recaudo del día',
            ],
        ],
        'vacio' => [
            'titulo' => 'Aún no hay recaudo en este período',
            'detalle' => 'Los movimientos bancarios llegan al día siguiente; en cuanto se carguen verás aquí el recaudo.',
        ],
    ],

    'grafico' => [
        'leyenda' => 'Leyenda',
        'resumen_serie' => ':serie: :valor (:mes)',
        'sin_dato' => 'Sin dato',
    ],
];
