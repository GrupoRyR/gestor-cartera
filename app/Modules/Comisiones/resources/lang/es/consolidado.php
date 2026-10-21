<?php

// The consolidated report (§8.1): screen, Excel workbook and PDF.
return [
    'titulo_pagina' => 'Consolidado de :mes',
    'hoja' => 'Consolidado',
    'descargar_excel' => 'Descargar Excel',
    'descargar_pdf' => 'Descargar PDF',
    'no_cerrada' => 'Esta versión aún no está cerrada: sus cifras pueden cambiar.',
    'generado' => 'Generado el :fecha por :nombre',

    'regular' => [
        'titulo' => 'Sección 1 · Equipo comercial regular',
        'tabla' => 'Comisiones del equipo comercial regular, con 81.1 y 85.1',
        'total' => 'Total equipo regular',
        'columnas' => [
            'codigo' => 'Código',
            'vendedor' => 'Vendedor',
            'venta' => 'Comisión venta',
            'recaudo' => 'Comisión recaudo',
            'obr' => 'Comisión OBR/Escudos',
            'reintegros' => 'Reintegros',
            'descuentos' => 'Descuentos',
            'director' => 'Comisión director',
            'total' => 'Total a pagar',
        ],
        'ver_codigo' => 'Ver la liquidación del código :codigo',
    ],

    'especiales' => [
        'titulo' => 'Sección 2 · Comisiones especiales',
        'tabla' => 'Códigos que se liquidan aparte',
        'columnas' => [
            'codigo' => 'Código',
            'nombre' => 'Nombre',
            'venta' => 'Venta',
            'comision' => 'Comisión de venta',
            'total' => 'Total a pagar',
        ],
    ],

    'directores' => [
        'titulo' => 'Sección 3 · Directores de canal',
        'equipo' => ':canal · :director',
        'tasa' => 'Tasa :tasa sobre la venta y el recaudo del equipo',
        'con_codigo' => 'Se paga con el total del código :codigo.',
        'tabla' => 'Venta y recaudo del equipo :canal',
        'total' => 'Total equipo',
        'comision' => 'Comisión',
        'nomina' => 'Valor pago nómina',
        'columnas' => [
            'codigo' => 'Código',
            'nombre' => 'Nombre',
            'recaudo' => 'Recaudo',
            'venta' => 'Venta',
        ],
    ],

    'personas' => [
        'titulo' => 'Anexo · Resumen por persona',
        'tabla' => 'Total por persona para nómina',
        'total' => 'Total para nómina',
        'columnas' => [
            'persona' => 'Persona',
            'roles' => 'Roles',
            'total' => 'Valor pago nómina',
        ],
    ],
];
