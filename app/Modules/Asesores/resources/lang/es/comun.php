<?php

return [

    'nav' => [
        'resumen' => 'Resumen',
        'clientes' => 'Clientes',
        'agenda' => 'Agenda',
        'historico' => 'Histórico',
        'equipo' => 'Equipo',
        'grupo' => 'Asesores',
    ],

    // The five age buckets of Core's AgingBuckets, by AgingRow::$key.
    'edades' => [
        'not_due' => 'Sin vencer',
        'days_1_30' => '1 a 30 días',
        'days_31_60' => '31 a 60 días',
        'days_61_90' => '61 a 90 días',
        'over_90' => 'Más de 90 días',
    ],

    'corte' => [
        'al' => 'Cartera al corte del :fecha.',
        'viejo_titulo' => 'Es posible que esta cartera esté desactualizada',
        'viejo' => 'El último corte es del :fecha, hace :dias días. Si las cifras no te cuadran, avisa al área de cartera.',
    ],

    'asesor' => [
        'etiqueta' => 'Asesor',
        'todos' => 'Todos los asesores',
        'ver' => 'Ver',
        'con_empresa' => ':nombre (:empresa)',
    ],

    'sin_gestion' => 'Sin gestión',
    'sin_datos' => '—',

];
