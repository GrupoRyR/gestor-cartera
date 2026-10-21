<?php

// The settlement of a period (GET /comisiones/periodos/{periodo}) and its
// steps: calculate, approve, close and complementary settlement.
return [
    'titulo' => 'Liquidación de :mes',

    'vacio' => [
        'titulo' => 'Aún no has calculado la liquidación de :mes',
        'descripcion' => 'Calcúlala cuando tengas las ventas, los recaudos, el presupuesto y los ajustes del mes. Puedes recalcularla las veces que necesites mientras el período esté abierto.',
        'descripcion_consulta' => 'Cuando el analista la calcule, la verás aquí.',
    ],

    'resumen' => [
        'estado' => 'Estado',
        'version' => 'Versión',
        'revision' => 'Revisión :revision',
        'total_regular' => 'Total equipo regular',
        'total_regular_ayuda' => 'Sección 1 del consolidado, con 81.1 y 85.1',
        'total_nomina' => 'Total para nómina',
        'total_nomina_ayuda' => 'Suma de lo que se paga a cada persona',
        'alertas' => 'Alertas bloqueantes',
        'alertas_ayuda' => ':advertencias advertencias',
    ],

    'trazabilidad' => [
        'titulo' => 'Quién hizo qué',
        'calculada' => 'Calculada',
        'aprobada' => 'Aprobada',
        'cerrada' => 'Cerrada',
        'por' => ':fecha por :nombre',
        'pendiente' => 'Pendiente',
        'motivo' => 'Motivo de la complementaria',
        'corrige' => 'Corrige la versión :version',
    ],

    'acciones' => [
        'calcular' => 'Calcular liquidación',
        'recalcular' => 'Recalcular',
        'recalcular_ayuda' => 'Vuelve a calcular con los datos de hoy. La versión queda en borrador como la revisión :revision y necesita una nueva aprobación.',
        'aprobar' => 'Aprobar liquidación',
        'cerrar' => 'Cerrar período',
        'cerrar_ayuda' => 'Al cerrar, la versión queda congelada y se habilitan los informes. Después solo se corrige con una liquidación complementaria.',
        'complementaria' => 'Registrar liquidación complementaria',
        'complementaria_ayuda' => 'Recalcula el período cerrado como una nueva versión en borrador. La versión cerrada no cambia.',
        'complementaria_registrar' => 'Registrar complementaria',
        'cancelar' => 'Cancelar',
        'motivo' => 'Motivo',
        'motivo_ayuda' => 'Explica qué se corrige, por ejemplo un recaudo que llegó tarde.',
    ],

    'aprobacion' => [
        'titulo' => 'Aprobación',
        'descripcion' => 'Revisas la revisión :revision. Si alguien la recalcula antes de que apruebes, te pediremos revisarla de nuevo.',
        'no_puedes' => 'No puedes aprobar esta liquidación',
        'mismo_analista' => 'Tú calculaste esta versión: otra persona debe aprobarla.',
        'justificacion' => 'Justificación',
        'justificacion_de' => 'Justificación de «:alerta»',
        'justificar' => 'Justifica cada alerta bloqueante antes de aprobar.',
        'sin_bloqueantes' => 'No hay alertas bloqueantes: puedes aprobarla sin justificaciones.',
    ],

    'alertas' => [
        'titulo' => 'Alertas',
        'tabla' => 'Alertas de la liquidación',
        'vacio' => 'La liquidación no tiene alertas.',
        'columnas' => [
            'tipo' => 'Tipo',
            'severidad' => 'Severidad',
            'mensaje' => 'Mensaje',
            'codigo' => 'Código',
            'justificacion' => 'Justificación',
        ],
    ],

    'versiones' => [
        'titulo' => 'Versiones',
        'tabla' => 'Versiones de la liquidación del período',
        'columnas' => [
            'version' => 'Versión',
            'estado' => 'Estado',
            'revision' => 'Revisión',
            'calculada' => 'Calculada',
            'motivo' => 'Motivo',
        ],
        'ver' => 'Ver versión :version',
        'actual' => 'Viendo',
    ],

    'mensajes' => [
        'calculada' => 'Calculaste la liquidación de :periodo: versión :version, revisión :revision.',
        'aprobada' => 'Aprobaste la versión :version de la liquidación de :periodo.',
        'cerrada' => 'Cerraste el período :periodo. La versión :version quedó congelada.',
        'complementaria' => 'Registraste la versión :version de :periodo como liquidación complementaria. Revísala y apruébala de nuevo.',
    ],

    'errores' => [
        'entradas_cambiaron' => 'Hay cambios en el presupuesto, en capturas aprobadas o en parámetros de :periodo después del cálculo de la revisión :revision. Recalcula la liquidación antes de :paso.',
        'pasos' => [
            'aprobar' => 'aprobarla',
            'cerrar' => 'cerrarla',
        ],
        'sin_liquidacion' => 'Todavía no has calculado la liquidación de :periodo.',
        'cerrada' => 'La liquidación de :periodo está cerrada. Para corregirla, registra una liquidación complementaria.',
        'transicion' => [
            'calcular' => 'La liquidación de :periodo no se puede recalcular en su estado actual.',
            'aprobar' => 'Solo se aprueba una liquidación en borrador. Recarga la página para ver su estado actual.',
            'cerrar' => 'Solo se cierra una liquidación aprobada.',
            'complementaria' => 'La liquidación de :periodo sigue abierta: recalcúlala en lugar de registrar una complementaria.',
        ],
        'desactualizada' => 'La liquidación se recalculó después de que la revisaste (revisión :antes → :ahora). Revísala de nuevo.',
        'mismo_analista' => 'Tú calculaste esta versión: otra persona debe aprobarla.',
        'sin_justificar' => 'Falta justificar una alerta bloqueante.|Faltan :cantidad alertas bloqueantes por justificar.',
        'justificacion_requerida' => 'Escribe la justificación de esta alerta.',
        'corrupta' => 'La liquidación guardada no pasó la verificación de integridad y no se puede usar. Avísale a TI antes de seguir.',
        'sin_parametros' => 'No hay una tabla de tramos de recaudo vigente para :periodo. Agrégala en Parámetros antes de calcular.',
        'datos_invalidos' => 'No pudimos completar el paso: revisa el motivo y las justificaciones e inténtalo de nuevo.',
    ],

    'validacion' => [
        'revision' => 'revisión',
        'justificaciones' => 'justificaciones',
        'motivo' => 'motivo',
    ],
];
