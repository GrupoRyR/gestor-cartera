<?php

// Receipts inbox (GET /soportes) and its menu entry, read with
// __('soportes::bandeja.<key>').
return [

    'menu' => 'Soportes de pago',

    'titulo' => 'Soportes de pago',
    'descripcion' => 'Los soportes que cargan los asesores y cartera. Aplica el pago en SAP y márcalo aquí como aplicado, o recházalo con el motivo.',
    'titulo_asesor' => 'Mis soportes',
    'descripcion_asesor' => 'Los soportes de tus clientes y los que cargaste. Aquí ves si cartera ya los aplicó o por qué los rechazó.',
    'cargar' => 'Cargar soporte',

    'pestanas' => [
        'etiqueta' => 'Estado de los soportes',
        'pendiente' => 'Pendientes',
        'aplicado' => 'Aplicados',
        'rechazado' => 'Rechazados',
    ],

    'filtros' => [
        'etiqueta' => 'Filtrar soportes',
        'buscar' => 'Cliente (nombre o código)',
        'desde' => 'Pagado desde',
        'hasta' => 'Pagado hasta',
        'aplicar' => 'Filtrar',
        'limpiar' => 'Limpiar filtros',
    ],

    'tabla' => 'Soportes de pago',
    'columnas' => [
        'cliente' => 'Cliente',
        'empresa' => 'Empresa',
        'valor' => 'Valor pagado',
        'fecha' => 'Fecha de pago',
        'facturas' => 'Facturas',
        'cargado_por' => 'Cargado por',
        'cargado' => 'Cargado',
        'estado' => 'Estado',
        'motivo' => 'Motivo del rechazo',
    ],

    'vacio' => [
        'asesor' => 'No tienes soportes en esta lista',
        'equipo' => 'No hay soportes en esta lista',
        'detalle' => 'Cuando se cargue un soporte, aparece aquí.',
        'con_filtros' => 'No encontramos soportes con esos filtros',
        'con_filtros_detalle' => 'Prueba con otro cliente u otras fechas.',
    ],

];
