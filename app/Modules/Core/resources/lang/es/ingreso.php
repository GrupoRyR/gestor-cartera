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
        'guest' => 'Las cuentas de invitado no pueden entrar al gestor de cartera. Ingresa con la cuenta de tu empresa.',
        'deleted' => 'Tu cuenta fue retirada del gestor de cartera. Habla con TI si necesitas volver a entrar.',
        'identity_mismatch' => 'Tu cuenta de Microsoft no coincide con la registrada para tu correo. Habla con TI para revisarlo.',
        'inactive' => 'Tu cuenta está desactivada. Habla con TI si crees que es un error.',
    ],

    // Notice of SendAdvisorRoleNotice when a sign-in gives the "asesor"
    // role on its own (audit S-17).
    'aviso_rol' => [
        'asunto' => 'Aviso: :nombre recibió el rol de asesor al ingresar al gestor de cartera',
        'titulo' => 'Rol de asesor asignado al ingresar',
        'intro' => 'Al ingresar con Microsoft, el correo de esta persona coincidió con el de uno o más asesores de SAP que no estaban vinculados a nadie, así que el gestor de cartera le dio el rol de asesor y ya puede ver los clientes de esos asesores.',
        'usuario' => 'Usuario: :nombre (:correo)',
        'cuando' => 'Cuándo: :fecha :hora (hora de Bogotá)',
        'empresas' => 'Empresas: :empresas',
        'asesores' => 'Asesores de SAP: :asesores',
        'que_hacer' => 'Qué hacer',
        'pasos' => 'Confirma que esta persona es el asesor de SAP con ese código. Si no lo es, quítale el rol y las empresas en Equipo y revisa quién cambió el correo del asesor en SAP (OSLP.Email).',
        'enlace' => 'Abrir en Equipo',
        'pie' => 'Este aviso lo envía el gestor de cartera cada vez que asigna el rol de asesor por coincidencia de correo con SAP.',
    ],

];
