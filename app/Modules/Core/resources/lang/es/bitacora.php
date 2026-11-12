<?php

// Client log texts (bitácora de gestiones), read with __('core::bitacora.<key>').
return [

    // Summaries Core writes in the log; stored as written, not translated on read.
    'resumen' => [
        'estado_automatico' => 'Estado automático: de «:anterior» a «:nuevo»',
        'estado_manual' => 'De «:anterior» a «:nuevo»',
        'gestor_asignado' => 'Gestor asignado: :nuevo',
        'gestor_cambiado' => 'Gestor cambiado: de :anterior a :nuevo',
        'gestor_quitado' => 'Gestor quitado: :anterior',
        'usuario' => 'usuario :id',
    ],

    'mensajes' => [
        'eliminada' => 'Eliminamos la gestión «:resumen».',
    ],

];
