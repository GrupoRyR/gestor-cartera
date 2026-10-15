<?php

// Sign-in with Microsoft and sign-out texts, read with
// __('core::ingreso.<key>').
return [

    'titulo' => 'Ingresar',
    'encabezado' => 'Ingresa al gestor de cartera',
    'detalle' => 'Usa tu cuenta de Microsoft de Segurex Latam o de Soluciones y Derivados.',
    'boton' => 'Ingresar con Microsoft',

    'sesion_cerrada' => 'Cerraste sesión.',
    'error' => 'No pudimos completar el ingreso con Microsoft. Inténtalo de nuevo.',

    // One per Application\Auth\RejectionReason.
    'rechazo' => [
        'tenant' => 'Esa cuenta de Microsoft no es del Grupo RYR. Ingresa con la cuenta de tu empresa.',
        'deleted' => 'Tu cuenta fue retirada del gestor de cartera. Habla con TI si necesitas volver a entrar.',
        'identity_mismatch' => 'Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.',
        'inactive' => 'Tu cuenta está desactivada. Habla con TI si crees que es un error.',
    ],

];
