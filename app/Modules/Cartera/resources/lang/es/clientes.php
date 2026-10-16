<?php

return [
    'titulo' => 'Clientes',
    'descripcion' => 'Todos los clientes con cartera. Busca por nombre o NIT y ordena por la columna que necesites.',

    'filtros' => [
        'etiqueta' => 'Filtros de clientes',
        'buscar' => 'Buscar por nombre o NIT',
        'buscar_ayuda' => 'Nombre o NIT del cliente',
        'estado' => 'Estado',
        'todos_estados' => 'Todos los estados',
        'asesor' => 'Asesor',
        'todos_asesores' => 'Todos los asesores',
        'vencidos' => 'Solo clientes con cartera vencida',
        'sin_gestion' => 'Solo vencidos sin gestión en los últimos :dias días',
        'aplicar' => 'Aplicar filtros',
        'limpiar' => 'Quitar filtros',
    ],

    'tabla' => 'Clientes con su saldo, valor vencido y estado',

    'columnas' => [
        'cliente' => 'Cliente',
        'empresa' => 'Empresa',
        'nit' => 'NIT',
        'total' => 'Total',
        'vencido' => 'Vencido',
        'dias' => 'Días de mora',
        'estado' => 'Estado',
        'asesor' => 'Asesor',
        'gestor' => 'Gestor',
        'ultima_gestion' => 'Última gestión',
    ],

    'sin_asesor' => 'Sin asesor',
    'sin_gestor' => 'Sin gestor',
    'sin_gestion' => 'Sin gestión',

    'vacio' => [
        'sin_clientes' => [
            'titulo' => 'Aún no hay clientes',
            'detalle' => 'Los clientes aparecerán cuando llegue la primera carga de cartera.',
        ],
        'sin_resultados' => [
            'titulo' => 'Ningún cliente coincide con los filtros',
            'detalle' => 'Prueba con otro nombre o NIT, o quita los filtros para ver todos los clientes.',
            'accion' => 'Quitar filtros',
        ],
    ],
];
