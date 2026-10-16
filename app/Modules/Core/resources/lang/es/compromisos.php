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
        'incumplido' => 'Compromiso incumplido: vencía el :fecha',
        'estado' => 'Por el compromiso de pago: de «:anterior» a «:nuevo»',
    ],

    // Output of core:compromisos-incumplidos, the daily task.
    'consola' => [
        'incumplidos' => '{0} No hay compromisos vencidos para marcar como incumplidos.|{1} Marcamos 1 compromiso como incumplido.|[2,*] Marcamos :total compromisos como incumplidos.',
        'fallidos' => '{1} No pudimos marcar 1 compromiso; el error quedó en el log.|[2,*] No pudimos marcar :total compromisos; los errores quedaron en el log.',
    ],

];
