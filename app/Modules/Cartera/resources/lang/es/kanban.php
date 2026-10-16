<?php

return [
    'titulo' => 'Kanban de cartera',
    'descripcion' => 'Cada columna es un estado de cobro. Arrastra un cliente o usa el botón «Mover» para cambiarlo de columna.',

    'tablero' => 'Clientes por estado de cobro',
    'mostrar_cerradas' => 'Mostrar «Al día» y «Pago recibido»',

    'columna' => [
        'resumen' => ':clientes · :total',
        'clientes' => '{0} Sin clientes|{1} 1 cliente|[2,*] :count clientes',
        'vacia' => 'Sin clientes en este estado.',
        'mas' => '{1} Y 1 cliente más|[2,*] Y :count clientes más',
        'ver_todos' => 'Ver todos en la lista',
    ],

    'tarjeta' => [
        'vencido' => 'Vencido',
        'total' => 'Total',
        'mora' => 'Mora máxima',
        'dias' => '{1} 1 día|[2,*] :count días',
        'al_dia' => 'Al día',
        'ultima_gestion' => 'Última gestión',
        'sin_gestion' => 'Sin gestión',
        'asesor' => 'Asesor',
        'gestor' => 'Gestor',
        'sin_asignar' => 'Sin asignar',
        'mover' => 'Mover',
        'mover_a' => 'cambiar el estado de :cliente',
        'arrastrar' => 'Arrastra la tarjeta a otra columna para cambiar el estado.',
    ],

    'mover' => [
        'titulo' => 'Mover cliente',
        'pregunta' => '¿A qué estado quieres mover a :cliente?',
        'actual' => 'estado actual',
        'cancelar' => 'Cancelar',
    ],

    'mensajes' => [
        'movido' => ':cliente pasó a «:estado».',
        'error' => 'No se pudo cambiar el estado. Recarga la página e inténtalo de nuevo.',
        'sin_permiso' => 'No tienes permiso para cambiar el estado de los clientes.',
    ],
];
