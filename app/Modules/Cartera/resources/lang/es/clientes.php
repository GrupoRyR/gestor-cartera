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
        'gestor' => 'Gestor',
        'gestor_opciones' => [
            'todos' => 'Toda la cartera',
            'mios' => 'Mi cartera',
            'sin_asignar' => 'Sin asignar',
        ],
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

    'asignar' => [
        'titulo' => 'Asignar gestor',
        'descripcion' => 'Elige clientes en la tabla, o todos los filtrados, y la persona que se encargará de ellos.',
        'gestor' => 'Nuevo gestor',
        'elige' => 'Elige una persona',
        'sin_asignar' => 'Sin asignar',
        'todos_filtrados' => '{1} Aplicar al cliente filtrado|[2,*] Aplicar a los :count clientes filtrados',
        'pagina' => 'Elegir los clientes de esta página',
        'columna' => 'Elegir',
        'elegir' => 'Elegir :cliente',
        'elegido' => '1 cliente elegido',
        'elegidos' => ':count clientes elegidos',
        'boton' => 'Asignar gestor',
        'hecho' => '{0} No cambió ningún cliente.|{1} Asignamos el gestor a :count cliente.|[2,*] Asignamos el gestor a :count clientes.',
        'errores' => [
            'sin_clientes' => 'Elige al menos un cliente o marca «todos los filtrados».',
            'gestor' => 'Elige a quién asignar los clientes.',
            'no_trabaja' => 'Esa persona no trabaja en la empresa de todos los clientes elegidos. Elige otra o filtra por empresa.',
            'no_disponible' => 'Alguno de los clientes ya no está disponible. Vuelve a cargar la lista.',
            'demasiados' => 'Son :count clientes; asigna de a máximo :max. Filtra un poco más.',
        ],
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
