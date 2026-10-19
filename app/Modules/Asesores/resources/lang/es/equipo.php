<?php

return [

    'titulo' => 'Mi equipo',
    'descripcion' => 'Cómo va la cartera de cada asesor, para saber con quién hablar primero.',

    'resumen' => '{1} 1 asesor · :vencido vencido en total|[0,*] :asesores asesores · :vencido vencido en total',

    'vacio' => [
        'titulo' => 'Aún no tienes asesores a tu cargo',
        'detalle' => 'Pide a TI que asigne los asesores de tu equipo en la pantalla Equipo.',
    ],

    'tabla' => 'Asesores del equipo y su cartera',
    'columnas' => [
        'asesor' => 'Asesor',
        'clientes' => 'Clientes',
        'saldo' => 'Saldo',
        'vencido' => 'Vencido',
        'mas_90' => 'Más de 90 días',
        'incumplidos' => 'Compromisos incumplidos',
        'acciones' => 'Acciones',
    ],
    'total' => 'Total del equipo',
    'inactivo' => 'inactivo en SAP',
    'ver' => 'Ver su cartera',
    'nota' => 'El porcentaje es la parte vencida sobre el saldo de cada asesor, para comparar carteras de tamaños distintos.',

    'volver' => 'Volver a mi equipo',
    'viendo' => 'Estás viendo la cartera de :nombre.',

];
