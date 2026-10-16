<?php

return [

    'titulo' => 'Histórico de cartera',
    'descripcion' => 'Una foto de la cartera en cada corte: cómo van el saldo, lo vencido y lo que pasa de 90 días.',
    'rangos' => [
        'etiqueta' => 'Periodo del histórico',
        'dias' => 'Últimos :dias días',
    ],
    'vacio' => [
        'titulo' => 'Todavía no hay cortes en este periodo',
        'detalle' => 'El robot guarda una foto de la cartera en cada carga. Prueba con un periodo más largo.',
    ],
    'tabla' => [
        'titulo' => 'Cortes del periodo',
        'columnas' => [
            'fecha' => 'Corte',
            'saldo' => 'Saldo',
            'vencido' => 'Vencido',
            'porcentaje' => '% vencido',
            'mas_90' => 'Más de 90 días',
            'clientes' => 'Clientes',
        ],
    ],

    'grafico' => [
        'titulo' => 'Saldo vencido en cada corte',
        'resumen' => 'Saldo vencido en :cortes cortes, del :desde al :hasta.',
        'subio' => 'Subió :valor desde el primer corte.',
        'bajo' => 'Bajó :valor desde el primer corte.',
        'igual' => 'No cambió desde el primer corte.',
        'poca_historia' => 'Aún hay un solo corte. Con dos o más cortes verás aquí cómo cambia la mora.',
        'tabla' => 'Ver como tabla',
        'vencido' => 'vencido',
    ],

];
