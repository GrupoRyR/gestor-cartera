<?php

// Why a batch was rejected, read with __('ingesta::validacion.<key>'). The
// robot receives these texts in the 422 response and the load history
// shows them. Rows are numbered from 0, like the "rows.N" error keys.
return [

    'batch_id' => [
        'requerido' => 'Falta el identificador del lote.',
        'largo' => 'El identificador del lote no puede tener más de :max caracteres.',
        'formato' => 'El identificador del lote solo admite letras sin tildes, números y los signos . _ : -',
    ],

    'corte_futuro' => 'La fecha de corte :corte es posterior a hoy (:hoy).',

    'sin_filas' => 'El lote de :lote llegó sin filas y este tipo de carga no puede estar vacío.',
    'demasiadas_filas' => 'El lote trae :filas filas y el máximo es :max.',

    'columnas_faltantes' => 'A la fila le faltan estas columnas: :columnas.',
    'columnas_desconocidas' => 'La fila trae columnas que no existen en :lote: :columnas.',

    'requerida' => 'La columna :columna es obligatoria.',
    'texto' => 'La columna :columna debe ser texto.',
    'texto_largo' => 'La columna :columna no puede tener más de :max caracteres.',
    'entero' => 'La columna :columna debe ser un número entero.',
    'decimal2' => 'La columna :columna debe ser un número con punto decimal, hasta 16 cifras enteras y 2 decimales, como 1234.56.',
    'decimal6' => 'La columna :columna debe ser un número con punto decimal y hasta 6 decimales, como 4123.456789.',
    'fecha' => 'La columna :columna debe ser una fecha real con formato AAAA-MM-DD.',

    'llave_repetida' => 'La fila repite la llave (:llave) de la fila :fila.',
    'vacio' => 'vacío',

    'resumen' => 'El lote tiene :count errores.',
    'resumen_tope' => 'El lote tiene :count errores o más; corrige estos primero.',

];
