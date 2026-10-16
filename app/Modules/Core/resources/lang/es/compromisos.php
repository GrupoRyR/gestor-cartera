<?php

// Payment commitment texts of Core, read with __('core::compromisos.<key>').
return [

    // Summaries Core writes in the client's log; stored as written, not translated on read.
    // :valor is an amount like "$ 2.500.000" and :fecha a date like "22/10/2026".
    'resumen' => [
        'creado' => 'Compromiso de pago por :valor para el :fecha',
        'actualizado' => 'Compromiso actualizado: :valor para el :fecha',
        'cumplido' => 'Compromiso cumplido: :valor para el :fecha',
        'anulado' => 'Compromiso anulado: :motivo',
        'estado' => 'Por el compromiso de pago: de «:anterior» a «:nuevo»',
    ],

];
