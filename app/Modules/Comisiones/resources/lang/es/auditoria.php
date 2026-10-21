<?php

// The audit trail screen (GET /comisiones/auditoria, §7.9, §10).
return [
    'titulo' => 'Auditoría de comisiones',
    'descripcion' => 'Todo lo que pasó en el módulo: cargas, parámetros, presupuesto, capturas, cálculos, aprobaciones, cierres y descargas. Solo se consulta; nadie puede editarla ni borrarla.',

    'filtros' => [
        'titulo' => 'Filtrar la bitácora',
        'usuario' => 'Usuario',
        'todos' => 'Todos',
        'sistema' => 'Sistema',
        'desde' => 'Desde',
        'hasta' => 'Hasta',
        'evento' => 'Evento',
        'periodo' => 'Período',
        'aplicar' => 'Filtrar',
        'limpiar' => 'Quitar los filtros',
    ],

    'tabla' => 'Eventos de la bitácora de comisiones',
    'vacio' => 'No hay eventos con esos filtros.',
    'total' => '{1} 1 evento|[0,*] :cantidad eventos',
    'columnas' => [
        'fecha' => 'Fecha y hora',
        'usuario' => 'Usuario',
        'evento' => 'Evento',
        'periodo' => 'Período',
        'sujeto' => 'Sujeto',
        'motivo' => 'Motivo',
        'detalle' => 'Detalle',
    ],
    'ver_detalle' => 'Ver el detalle',
    'cambios' => 'Cambios',
    'contexto' => 'Contexto',
    'sin_detalle' => 'Sin detalle',
    'ip' => 'IP :ip',

    // Event labels: "comisiones.<entity>.<verb>" → eventos.<entity>.<verb>.
    // An event without a label here shows its name.
    'eventos' => [
        'load' => [
            'imported' => 'Carga de SAP importada',
        ],
        'assignment' => [
            'start_moved' => 'Cambio de vendedor aplicado desde el día siguiente',
        ],
        'parameter' => [
            'version_added' => 'Versión de parámetro guardada',
        ],
        'role' => [
            'assigned' => 'Rol asignado',
            'removed' => 'Rol quitado',
        ],
        'budget' => [
            'updated' => 'Presupuesto editado',
            'deleted' => 'Presupuesto borrado',
            'imported' => 'Presupuesto cargado desde archivo',
        ],
        'capture' => [
            'created' => 'Captura registrada',
            'approved' => 'Captura aprobada',
            'rejected' => 'Captura rechazada',
            'deleted' => 'Captura borrada',
        ],
        'support' => [
            'downloaded' => 'Soporte descargado',
        ],
        'settlement' => [
            'calculated' => 'Liquidación calculada',
            'approved' => 'Liquidación aprobada',
            'closed' => 'Liquidación cerrada',
            'complemented' => 'Liquidación complementaria registrada',
        ],
        'export' => [
            'downloaded' => 'Consolidado descargado',
        ],
        'report' => [
            'generated' => 'Informe generado',
            'downloaded' => 'Informe descargado',
        ],
    ],

    // Subject types; one without a label here shows its name.
    'sujetos' => [
        'liquidacion' => 'Liquidación',
        'documento' => 'Informe',
        'presupuesto' => 'Presupuesto',
        'obr' => 'OBR',
        'ajuste' => 'Reintegro o descuento',
        'cruce' => 'Cruce manual',
        'reasignacion' => 'Reasignación',
        'comisiones_carga' => 'Carga de SAP',
        'cliente' => 'Cliente',
        'comisiones_parametro' => 'Parámetro',
        'user' => 'Usuario',
    ],
];
