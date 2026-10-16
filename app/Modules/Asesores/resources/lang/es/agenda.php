<?php

return [

    'titulo' => 'Agenda de compromisos',
    'descripcion' => 'Lo que tus clientes prometieron pagar, de lo más urgente a lo más lejano.',

    'vacio' => [
        'titulo' => 'No tienes compromisos abiertos',
        'detalle' => 'Cuando un cliente prometa una fecha de pago, regístrala desde su ficha y aparecerá aquí.',
    ],

    'truncada' => 'Hay más compromisos abiertos de los que caben aquí. Mostramos los :limite con la fecha más cercana.',

    'resumen' => [
        'titulo' => 'Resumen de la agenda',
        'incumplidos' => 'Incumplidos',
        'hoy' => 'Vencen hoy',
        'semana' => 'Próximos 7 días',
        'cantidad' => '{0} Ninguno|{1} 1 compromiso|[2,*] :cantidad compromisos',
    ],

    'grupos' => [
        'broken' => [
            'titulo' => 'Incumplidos',
            'ayuda' => 'Pasó la fecha prometida y el cliente no ha pagado. Llámalo hoy.',
        ],
        'today' => [
            'titulo' => 'Vencen hoy',
            'ayuda' => 'La última oportunidad de cobrar sin que se incumplan.',
        ],
        'week' => [
            'titulo' => 'Próximos 7 días',
            'ayuda' => 'Confirma con el cliente antes de que llegue la fecha.',
        ],
        'later' => [
            'titulo' => 'Más adelante',
            'ayuda' => 'Compromisos con fecha de pago a más de una semana.',
        ],
        'titulo_con_total' => ':titulo · :cantidad · :valor',
    ],

    'tabla' => [
        'cliente' => 'Cliente',
        'fecha' => 'Fecha de pago',
        'valor' => 'Valor',
        'estado' => 'Estado',
        'acciones' => 'Acciones',
    ],

    'cuando' => [
        'hoy' => 'hoy',
        'atras' => '{1} hace 1 día|[2,*] hace :dias días',
        'adelante' => '{1} mañana|[2,*] en :dias días',
    ],

];
