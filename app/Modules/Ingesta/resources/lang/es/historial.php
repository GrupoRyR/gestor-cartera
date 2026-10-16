<?php

// Load history screens (GET /ingesta and /ingesta/cargas/{load}), read
// with __('ingesta::historial.<key>').
return [

    'titulo' => 'Cargas de datos',
    'descripcion' => 'Lo que llegó del robot de SAP y de las cargas manuales, aceptado o rechazado, con su fecha de corte.',

    'filtros' => [
        'etiqueta' => 'Filtrar cargas',
        'lote' => 'Lote',
        'empresa' => 'Empresa',
        'estado' => 'Estado',
        'todos' => 'Todos',
        'todas' => 'Todas',
        'aplicar' => 'Filtrar',
        'limpiar' => 'Quitar filtros',
    ],

    'tabla' => 'Historial de cargas',

    'columnas' => [
        'recibida' => 'Recibida',
        'lote' => 'Lote',
        'empresa' => 'Empresa',
        'corte' => 'Fecha de corte',
        'origen' => 'Origen',
        'estado' => 'Estado',
        'filas' => 'Filas',
        'total' => 'Total',
        'vencido' => 'Vencido',
        'usuario' => 'Usuario',
        'acciones' => 'Acciones',
    ],

    'ver' => 'Ver detalle',
    'ver_de' => 'de la carga de :lote del :fecha',

    'vacio' => [
        'titulo' => 'Aún no hay cargas',
        'detalle' => 'Cuando el robot publique un lote o alguien suba el Excel de cartera, la carga aparece aquí.',
    ],

    'vacio_filtrado' => [
        'titulo' => 'No hay cargas con estos filtros',
        'detalle' => 'Cambia o quita los filtros para ver más cargas.',
    ],

    'vigente' => [
        'titulo' => 'Cartera vigente de :empresa',
        'descripcion' => 'La última carga de cartera aceptada.',
        'corte' => 'Fecha de corte',
        'origen' => 'Origen',
        'total' => 'Saldo total',
        'vencido' => 'Saldo vencido',
        'sin_carga' => 'Aún no hay una carga de cartera aceptada.',
    ],

    'alerta' => [
        'titulo' => 'Falta la cartera de hoy de :empresa',
        'detalle' => 'A las :hora no había llegado la carga del robot con corte :fecha. Revisa el robot o sube el Excel con la carga manual.',
    ],

    'detalle' => [
        'titulo' => ':lote con corte :fecha',
        'datos' => 'Datos de la carga',
        'estado' => 'Estado',
        'empresa' => 'Empresa',
        'lote' => 'Lote',
        'corte' => 'Fecha de corte',
        'origen' => 'Origen',
        'filas' => 'Filas',
        'recibida' => 'Recibida',
        'usuario' => 'Usuario',
        'sin_usuario' => 'Robot de SAP',
        'identificador' => 'Identificador del lote',
        'totales' => 'Totales de la cartera',
        'total' => 'Saldo total',
        'vencido' => 'Saldo vencido',
        'vencido_ayuda' => 'Saldo de las cuotas con días de mora.',
        'rechazada' => 'La carga se rechazó',
        'errores' => 'Errores por campo',
        'errores_columnas' => [
            'campo' => 'Campo',
            'mensajes' => 'Qué pasó',
        ],
        'volver' => 'Volver al historial',
    ],

    // How an error key ("rows.3.saldo") reads; rows are numbered from 0,
    // like the messages.
    'campos' => [
        'batch_id' => 'Identificador del lote',
        'cutoff_date' => 'Fecha de corte',
        'rows' => 'Filas',
        'body' => 'Contenido',
        'fila' => 'Fila :fila',
        'fila_columna' => 'Fila :fila, columna :columna',
    ],

];
