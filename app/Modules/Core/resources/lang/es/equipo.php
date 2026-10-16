<?php

// Equipo screen texts (admins), read with __('core::equipo.<key>').
return [

    'titulo' => 'Equipo',
    'descripcion' => 'Las personas que usan la plataforma: su rol, sus empresas y si pueden ingresar.',
    'nuevo' => 'Nuevo usuario',
    'sin_rol' => 'Sin rol',
    'todas' => 'Todas',

    'pestanas' => [
        'etiqueta' => 'Estado de los usuarios',
        'activos' => 'Activos',
        'sin-rol' => 'Sin rol',
        'inactivos' => 'Inactivos',
        'eliminados' => 'Eliminados',
    ],

    'tabla' => [
        'activos' => 'Usuarios activos',
        'sin-rol' => 'Usuarios sin rol',
        'inactivos' => 'Usuarios inactivos',
        'eliminados' => 'Usuarios eliminados',
    ],

    'columnas' => [
        'nombre' => 'Nombre',
        'correo' => 'Correo',
        'rol' => 'Rol',
        'empresas' => 'Empresas',
        'estado' => 'Estado',
        'acciones' => 'Acciones',
    ],

    'estados' => [
        'activo' => 'Activo',
        'inactivo' => 'Inactivo',
        'eliminado' => 'Eliminado',
    ],

    'acciones' => [
        'editar' => 'Editar',
        'restaurar' => 'Restaurar',
        'a' => 'a :nombre',
    ],

    'buscar' => [
        'etiqueta' => 'Buscar usuarios',
        'ayuda' => 'Nombre o correo',
        'boton' => 'Buscar',
    ],

    // Empty states: one per tab, and one for a search without results.
    'vacio' => [
        'activos' => [
            'titulo' => 'Aún no hay usuarios activos',
            'detalle' => 'Aquí aparecen las personas activas que tienen un rol.',
        ],
        'sin-rol' => [
            'titulo' => 'No hay solicitudes de acceso pendientes',
            'detalle' => 'Aquí aparecen las personas que ingresaron con Microsoft y esperan un rol.',
        ],
        'inactivos' => [
            'titulo' => 'No hay usuarios inactivos',
            'detalle' => 'Aquí aparecen las cuentas desactivadas.',
        ],
        'eliminados' => [
            'titulo' => 'No hay usuarios eliminados',
            'detalle' => 'Aquí aparecen las cuentas eliminadas.',
        ],
        'busqueda' => [
            'titulo' => 'No encontramos usuarios con «:buscar»',
            'detalle' => 'Revisa lo que escribiste o busca en otra pestaña.',
        ],
    ],

    'formulario' => [
        'nuevo_detalle' => 'Registra a una persona antes de su primer ingreso con Microsoft. Si su correo era de una cuenta eliminada, la restauramos.',
        'nombre' => 'Nombre',
        'correo' => 'Correo',
        'correo_ayuda' => 'El correo de Microsoft con el que va a ingresar.',
        'rol' => 'Rol',
        'rol_ayuda' => 'Sin rol, la persona ve la pantalla que le pide solicitar acceso.',
        'empresas' => 'Empresas',
        'empresas_ayuda' => 'Elige al menos una para cualquier rol, salvo administrador: los administradores ven las dos.',
        'activo' => 'Cuenta activa',
        'activo_ayuda' => 'Si la desactivas, la persona sale de la plataforma en su próxima página y no puede volver a ingresar.',
        'crear' => 'Registrar',
        'guardar' => 'Guardar cambios',
        'cancelar' => 'Cancelar',
    ],

    'eliminar' => [
        'titulo' => 'Eliminar usuario',
        'detalle' => 'La cuenta deja de ingresar, pero se conserva en la bitácora y puedes restaurarla desde «Eliminados».',
        'boton' => 'Eliminar usuario',
        'confirmar' => '¿Eliminar la cuenta de :nombre?',
        'confirmar_detalle' => 'Sale de la plataforma de inmediato. Su rol y sus empresas se conservan por si la restauras.',
        'si' => 'Eliminar',
        'no' => 'Cancelar',
    ],

    'mensajes' => [
        'creado' => 'Registramos a :nombre. Podrá ingresar con Microsoft usando ese correo.',
        'restaurado_al_crear' => 'Restauramos la cuenta eliminada de :nombre con los datos del formulario.',
        'actualizado' => 'Guardamos los cambios de :nombre.',
        'eliminado' => 'Eliminamos la cuenta de :nombre. Puedes restaurarla desde «Eliminados».',
        'restaurado' => 'Restauramos la cuenta de :nombre.',
        'no_eliminado' => 'La cuenta de :nombre no estaba eliminada.',
    ],

    'rechazos' => [
        'self_deactivation' => 'No puedes desactivar tu propia cuenta.',
        'self_demotion' => 'No puedes quitarte el rol de administrador.',
        'self_deletion' => 'No puedes eliminar tu propia cuenta.',
        'email_taken' => 'Ya existe un usuario con ese correo.',
    ],

    // Attribute names inside the validation messages ("El campo nombre…").
    'campos' => [
        'nombre' => 'nombre',
        'correo' => 'correo',
        'rol' => 'rol',
        'empresas' => 'empresas',
        'empresa' => 'empresa',
        'activo' => 'cuenta activa',
    ],

    'validacion' => [
        'correo_existe' => 'Ya existe un usuario con ese correo.',
        'empresas' => 'Elige al menos una empresa para este rol.',
    ],

];
