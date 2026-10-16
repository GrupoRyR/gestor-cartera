<?php

// Equipo screen texts (admins), read with __('core::equipo.<key>').
return [

    'titulo' => 'Equipo',
    'descripcion' => 'Las personas que usan la plataforma: su rol, sus empresas y si pueden ingresar.',
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
    ],

    'estados' => [
        'activo' => 'Activo',
        'inactivo' => 'Inactivo',
        'eliminado' => 'Eliminado',
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

];
