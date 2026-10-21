<?php

// Texts shared by the Comisiones screens and exports.
return [
    'modulo' => 'Comisiones',
    'mes_anio' => ':mes de :anio',
    'titulo_informe' => 'Informe de comisiones — período calculado del :desde al :hasta de :mes de :anio',

    'meses' => [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ],

    // SettlementStatus values, as the screens and reports name them.
    'estados' => [
        'borrador' => 'Borrador',
        'aprobada' => 'Aprobada (sin cerrar)',
        'cerrada' => 'Cerrada',
    ],

    // Tabs of a period (resumen, consolidado, controles, informes).
    'pestanas' => [
        'etiqueta' => 'Secciones del período',
        'resumen' => 'Resumen',
        'consolidado' => 'Consolidado',
        'controles' => 'Controles',
        'informes' => 'Informes',
    ],

    'contexto_periodo' => 'Comisiones · :mes',
    'version' => 'Versión :version',
    'version_revision' => 'Versión :version · revisión :revision',
    'viendo_version' => 'Estás viendo la versión :version. La vigente es la :vigente.',
    'ver_vigente' => 'Ver la versión vigente',
    'sin_dato' => '—',

    // Captures of a period whose latest settlement version is closed (PeriodLock).
    'periodo_cerrado' => 'El período está cerrado. Para corregirlo registra una liquidación complementaria.',
];
