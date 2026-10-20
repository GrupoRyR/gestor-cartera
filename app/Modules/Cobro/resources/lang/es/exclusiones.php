<?php

// Exclusion list: clients that receive no collection mail.
return [

    'titulo' => 'Lista de exclusión',
    'descripcion' => 'Marca los clientes a los que no se les envía el cobro semanal ni la circularización. La exclusión puede vencer en una fecha.',

    'buscar' => [
        'etiqueta' => 'Buscar clientes',
        'campo' => 'Buscar por nombre o NIT',
        'todos' => 'Mostrar también clientes sin saldo vencido',
        'aplicar' => 'Buscar',
        'limpiar' => 'Limpiar',
    ],

    'clientes' => [
        'titulo' => 'Clientes',
        'descripcion' => 'Marca las casillas, escribe el motivo y guarda. Si el cliente ya estaba excluido, se actualizan el motivo y la fecha.',
        'tabla' => 'Clientes que puedes excluir',
        'columnas' => [
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'nit' => 'NIT',
            'vencido' => 'Vencido',
            'dias' => 'Días de mora',
            'estado' => 'En la lista',
        ],
        'excluido' => 'Excluido',
        'excluido_hasta' => 'Excluido hasta el :fecha',
        'vacio' => [
            'titulo' => 'No hay clientes para mostrar',
            'detalle' => 'Prueba con otra búsqueda o muestra también los clientes sin saldo vencido.',
        ],
    ],

    'formulario' => [
        'motivo' => 'Motivo',
        'motivo_ayuda' => 'Por ejemplo: acuerdo de pago vigente, cliente en proceso jurídico.',
        'vence' => 'La exclusión vence el',
        'vence_ayuda' => 'Opcional. Después de esta fecha el cliente vuelve a recibir el cobro.',
        'guardar' => 'Excluir clientes marcados',
    ],

    'excluidos' => [
        'titulo' => 'Clientes excluidos',
        'descripcion' => 'Marca los clientes que deben volver a recibir el cobro y quítalos de la lista.',
        'tabla' => 'Clientes en la lista de exclusión',
        'columnas' => [
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'motivo' => 'Motivo',
            'vence' => 'Vence',
            'por' => 'Excluido por',
            'desde' => 'Desde',
        ],
        'sin_vencimiento' => 'Sin fecha',
        'vencida' => 'Vencida',
        'quitar' => 'Quitar de la lista',
        'vacio' => [
            'titulo' => 'Aún no hay clientes excluidos',
            'detalle' => 'Todos los clientes con saldo vencido reciben el cobro.',
        ],
    ],

    'mensajes' => [
        'excluidos' => '{1} Excluiste 1 cliente del cobro.|[2,*] Excluiste :total clientes del cobro.',
        'quitados' => '{0} No se quitó ningún cliente.|{1} Quitaste 1 cliente de la lista de exclusión.|[2,*] Quitaste :total clientes de la lista de exclusión.',
        'sin_clientes' => 'Ninguno de los clientes marcados está a tu alcance.',
    ],

    'validacion' => [
        'clientes' => 'clientes',
        'motivo' => 'motivo',
        'vence' => 'fecha de vencimiento',
        'exclusiones' => 'clientes excluidos',
        'marca_uno' => 'Marca al menos un cliente.',
    ],

];
