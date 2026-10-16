<?php

// Home, no-access screen and inactive account texts, read with
// __('core::acceso.<key>').
return [

    'inicio' => [
        'titulo' => 'Inicio',
        'saludo' => 'Hola, :nombre',
        'vacio' => 'Aún no tienes pantallas habilitadas',
        'vacio_detalle' => 'Tus pantallas aparecerán aquí a medida que se habiliten los módulos.',
    ],

    'sin_acceso' => [
        'titulo' => 'Todavía no tienes acceso',
        'pagina' => 'Sin acceso',
        'detalle' => 'Tu cuenta de Microsoft ya está registrada, pero aún no tiene un rol asignado. Pide al área de tecnología (TI) que te asigne uno.',
        'cuenta' => 'Ingresaste como :email.',
        'salir' => 'Salir',
    ],

    'cuenta_desactivada' => 'Tu cuenta está desactivada. Habla con TI si crees que es un error.',

];
