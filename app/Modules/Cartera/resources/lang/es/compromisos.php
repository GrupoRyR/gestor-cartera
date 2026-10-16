<?php

return [
    'mensajes' => [
        'registrado' => 'Compromiso registrado.',
        'actualizado' => 'Compromiso actualizado.',
        'cumplido' => 'Compromiso marcado como cumplido.',
        'anulado' => 'Compromiso anulado.',
        'cerrado' => 'Este compromiso ya no se puede cambiar.',
    ],

    'cancelar' => 'Cancelar',

    'nuevo' => [
        'boton' => 'Nuevo compromiso de pago',
        'titulo' => 'Nuevo compromiso de pago',
        'fecha' => 'Fecha de pago',
        'fecha_ayuda' => 'Hoy o una fecha posterior.',
        'valor' => 'Valor comprometido',
        'valor_ayuda' => 'En pesos colombianos, sin puntos ni comas.',
        'notas' => 'Notas',
        'notas_ayuda' => 'Cómo y quién va a pagar, o cualquier acuerdo con el cliente.',
        'guardar' => 'Guardar compromiso',
    ],

    'editar' => [
        'boton' => 'Editar',
        'titulo' => 'Editar compromiso',
        'ayuda' => 'Deja vacío lo que no quieras cambiar.',
        'fecha' => 'Nueva fecha de pago',
        'valor' => 'Nuevo valor',
        'notas' => 'Notas',
        'guardar' => 'Guardar cambios',
        'de' => 'del compromiso de :cliente por :valor',
    ],

    'cumplir' => [
        'boton' => 'Marcar cumplido',
        'de' => 'el compromiso de :cliente por :valor',
    ],

    'anular' => [
        'boton' => 'Anular',
        'titulo' => 'Anular compromiso',
        'pregunta' => '¿Por qué se anula el compromiso de :cliente por :valor?',
        'motivo' => 'Motivo',
        'motivo_ayuda' => 'Queda en la bitácora del cliente.',
        'guardar' => 'Anular compromiso',
        'de' => 'el compromiso de :cliente por :valor',
    ],

    'acciones' => 'Acciones',

    'bandeja' => [
        'titulo' => 'Compromisos de pago',
        'descripcion' => 'Todo lo que los clientes prometieron pagar, con sus fechas y quién lo registró.',
        'solo_lectura_sgx' => 'En SGX los compromisos los registra el asesor del cliente; aquí los ves todos.',
        'tabla' => 'Compromisos de pago',
        'total' => 'Total de esta página',

        'pestanas' => [
            'etiqueta' => 'Estado del compromiso',
            'pendientes' => 'Pendientes',
            'incumplidos' => 'Incumplidos',
            'cumplidos' => 'Cumplidos',
            'anulados' => 'Anulados',
            'todos' => 'Todos',
        ],

        'filtros' => [
            'etiqueta' => 'Filtros de compromisos',
            'asesor' => 'Asesor',
            'todos_asesores' => 'Todos los asesores',
            'desde' => 'Vence desde',
            'hasta' => 'Vence hasta',
            'aplicar' => 'Aplicar filtros',
            'limpiar' => 'Quitar filtros',
        ],

        'columnas' => [
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'asesor' => 'Asesor',
            'vence' => 'Vence',
            'valor' => 'Valor',
            'estado' => 'Estado',
            'registrado_por' => 'Registrado por',
            'registrado' => 'Registrado',
            'notas' => 'Notas',
        ],

        'sin_asesor' => 'Sin asesor',

        'vacio' => [
            'sin_filtros' => [
                'titulo' => 'No hay compromisos en esta pestaña',
                'detalle' => 'Cuando un cliente prometa una fecha de pago, aparecerá aquí.',
            ],
            'con_filtros' => [
                'titulo' => 'Ningún compromiso coincide con los filtros',
                'detalle' => 'Prueba con otras fechas u otro asesor, o quita los filtros.',
                'accion' => 'Quitar filtros',
            ],
        ],
    ],
];
