<?php

// Collection report: every weekly collection and circularization mail, with its replies.
return [

    'titulo' => 'Informe de cobro',
    'descripcion' => 'Cada correo del cobro semanal y de la circularización: a quién le llegó, quién respondió y cuánto tardó.',

    'filtros' => [
        'etiqueta' => 'Filtrar el informe',
        'desde' => 'Desde',
        'hasta' => 'Hasta',
        'empresa' => 'Empresa',
        'cliente' => 'Cliente',
        'asesor' => 'Asesor',
        'tipo' => 'Envío',
        'estado' => 'Resultado',
        'respondio' => '¿Respondió?',
    ],
    'filtro' => [
        'todas' => 'Todas',
        'todos' => 'Todos',
        'cliente_ayuda' => 'Código, nombre o NIT.',
        'si' => 'Sí',
        'no' => 'No',
        'respondio_ayuda' => 'Entre los correos que llegaron al buzón.',
        'aplicar' => 'Filtrar',
        'limpiar' => 'Limpiar filtros',
    ],

    'metricas' => [
        'enviados' => 'Correos enviados',
        'enviados_ayuda' => 'Enviados y rebotados.',
        'fallidos' => 'Fallidos',
        'rebotados' => 'Rebotados',
        'clientes' => 'Clientes alcanzados',
        'respuestas' => 'Respuestas',
        'respuestas_ayuda' => 'Clientes que respondieron al menos una vez.',
        'tasa' => 'Tasa de respuesta',
        'mediana' => 'Mediana hasta la primera respuesta',
        'equipo' => 'Respuestas que envió el equipo',
        'equipo_ayuda' => 'Desde la app, en estas fechas y empresas; los demás filtros no aplican.',
    ],
    'duracion' => [
        'horas' => ':valor h',
        'dias' => ':valor días',
    ],

    'tabla' => 'Correos del informe',
    'columnas' => [
        'fecha' => 'Fecha',
        'empresa' => 'Empresa',
        'tipo' => 'Envío',
        'cliente' => 'Cliente',
        'asesor' => 'Asesor',
        'estado' => 'Resultado',
        'monto' => 'Valor',
        'facturas' => 'Facturas',
        'enviado' => 'Enviado',
        'respuesta' => 'Primera respuesta',
    ],
    'sin_respuesta' => 'Sin respuesta',
    'vacio' => [
        'titulo' => 'No hay correos con estos filtros',
        'detalle' => 'Cambia las fechas o quita algún filtro.',
    ],

    'exportar' => [
        'boton' => 'Descargar Excel',
        'archivo' => 'informe-cobro-:desde-a-:hasta',
        'hoja' => 'Informe de cobro',
        'columnas' => [
            'fecha' => 'Fecha del envío',
            'empresa' => 'Empresa',
            'tipo' => 'Envío',
            'codigo' => 'Código del cliente',
            'cliente' => 'Cliente',
            'nit' => 'NIT',
            'asesor' => 'Asesor',
            'estado' => 'Resultado',
            'monto' => 'Valor',
            'facturas' => 'Facturas',
            'enviado' => 'Enviado',
            'respuesta' => 'Primera respuesta',
            'horas' => 'Horas hasta la primera respuesta',
            'detalle' => 'Detalle',
        ],
    ],

];
