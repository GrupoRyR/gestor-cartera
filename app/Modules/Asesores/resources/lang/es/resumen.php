<?php

return [

    'titulo' => 'Mi cartera',
    'titulo_equipo' => 'Cartera de mi equipo',
    'titulo_asesor' => 'Cartera de :nombre',
    'descripcion' => 'Lo que te deben tus clientes, cuánto está vencido y a quién conviene llamar primero.',

    'vacio' => [
        'titulo' => 'Aún no hay cartera para mostrar',
        'detalle' => 'Cuando el robot publique la primera carga, la verás aquí. Si ya debería haber datos, pide a TI que vincule tu usuario con tu código de asesor.',
    ],

    'kpis' => [
        'saldo' => 'Saldo total',
        'sin_vencer' => 'Sin vencer',
        'vencido' => 'Vencido',
        'mas_90' => 'Más de 90 días',
        'clientes' => 'Clientes con saldo',
        'clientes_vencido' => 'Clientes con vencido',
        'del_total' => ':porcentaje del total',
        'dificil_cobro' => 'Cartera de difícil cobro',
        'de_clientes' => 'de :total clientes',
    ],

    'edades' => [
        'titulo' => 'Antigüedad de la cartera',
        'ayuda' => 'Saldo por días de vencida al corte. Una barra gris es un saldo a favor, como una nota crédito o un anticipo.',
        'a_favor' => 'a favor',
        'tabla' => 'Ver como tabla',
        'tabla_titulo' => 'Saldo por antigüedad',
        'columnas' => [
            'rango' => 'Antigüedad',
            'saldo' => 'Saldo',
            'participacion' => 'Del total',
        ],
        'vacio' => 'No hay saldos para mostrar.',
    ],

    'prioridad' => [
        'titulo' => 'Atención prioritaria',
        'ayuda' => 'Clientes con facturas vencidas hace más de :dias días.',
        'vacio' => 'Ningún cliente tiene facturas vencidas hace más de :dias días.',
        'dias' => ':dias días de mora',
        'ver_todos' => 'Ver todos los clientes',
    ],

    'tendencia' => [
        'titulo' => 'Cómo evoluciona la mora',
        'ayuda' => 'Saldo vencido en cada corte de los últimos :dias días.',
        'ver_historico' => 'Ver el histórico',
    ],

];
