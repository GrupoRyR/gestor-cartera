<?php

// Answers of the robot endpoint (POST /api/ingesta/{lote}), read with
// __('ingesta::robot.<key>'). The robot logs them when a stage fails.
return [

    // The same text for every signature failure, so it gives no hint.
    'firma_invalida' => 'Firma inválida.',

    'demasiado_grande' => 'El lote pesa más de :max bytes, el máximo que se acepta.',
    'codificacion' => 'Envía el cuerpo como JSON sin comprimir o con Content-Encoding: gzip.',
    'gzip_invalido' => 'El cuerpo no es un gzip válido o descomprimido pasa de :max bytes.',
    'json_invalido' => 'El cuerpo no es un JSON válido.',
    'json_objeto' => 'El cuerpo debe ser un objeto JSON con batch_id, company, cutoff_date, columns y rows.',

    // The columns of the envelope, checked against the dataset before any row.
    'columnas_demasiadas' => 'El lote trae :count columnas y el máximo es :max.',
    'columnas_faltantes' => 'Al lote le faltan estas columnas: :columnas.',
    'columnas_desconocidas' => 'El lote trae columnas que no existen en :lote: :columnas.',

    'filas_lista' => 'El campo rows debe ser una lista de filas.',
    'fila_lista' => 'La fila debe ser una lista con :count valores, uno por columna.',
    'fila_valores' => 'Los valores de la fila deben ser texto, números o null, no listas ni objetos.',

];
