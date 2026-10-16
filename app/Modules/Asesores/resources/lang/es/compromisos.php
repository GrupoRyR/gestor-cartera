<?php

return [

    'nuevo' => [
        'boton' => 'Nuevo compromiso',
        'titulo' => 'Nuevo compromiso de pago',
        'guardar' => 'Guardar compromiso',
        'cancelar' => 'Cancelar',
    ],

    'campos' => [
        'fecha' => [
            'nombre' => 'fecha de pago',
            'etiqueta' => '¿Qué día prometieron pagar?',
            'ayuda' => 'Hoy o una fecha futura.',
        ],
        'valor' => [
            'nombre' => 'valor prometido',
            'etiqueta' => 'Valor prometido',
            'ayuda' => 'En pesos, por ejemplo 5.000.000.',
            'ayuda_vencido' => 'En pesos, por ejemplo 5.000.000. Hoy el cliente tiene :vencido vencido.',
        ],
        'notas' => [
            'nombre' => 'notas',
            'etiqueta' => 'Notas',
            'ayuda' => 'Con quién hablaste y qué te dijeron. Es opcional.',
        ],
        'nota' => [
            'nombre' => 'nota',
            'etiqueta' => 'Nota',
            'ayuda' => 'Por ejemplo, cómo pagó el cliente. Es opcional.',
        ],
        'motivo' => [
            'nombre' => 'motivo',
            'etiqueta' => 'Motivo',
            'ayuda' => 'Por qué anulas el compromiso. Queda en la bitácora del cliente.',
        ],
    ],

    'ver' => 'Ver y gestionar',
    'detalle' => 'Ver detalle',

    'show' => [
        'titulo' => 'Compromiso de pago',
        'volver' => 'Volver al cliente',
        'cliente' => 'Cliente',
        'valor' => 'Valor prometido',
        'fecha' => 'Fecha de pago',
        'estado' => 'Estado',
        'registrado' => 'Registrado por :nombre el :fecha',
        'cumplido' => 'Cumplido el :fecha',
        'solo_lectura' => 'Puedes ver este compromiso, pero solo el asesor del cliente lo modifica.',
        'cerrado' => 'Este compromiso ya está cerrado: no se puede modificar.',
        'editar' => [
            'titulo' => 'Cambiar fecha o valor',
            'ayuda' => 'Solo se puede cambiar mientras está pendiente.',
            'guardar' => 'Guardar cambios',
        ],
        'cumplir' => [
            'titulo' => 'Marcar como cumplido',
            'ayuda' => 'El cliente pagó. Un pago tardío también cuenta como cumplido.',
            'boton' => 'Marcar como cumplido',
        ],
        'anular' => [
            'titulo' => 'Anular el compromiso',
            'ayuda' => 'Úsalo si se registró por error o el acuerdo cambió. No se puede deshacer.',
            'boton' => 'Anular compromiso',
        ],
    ],

    'mensajes' => [
        'creado' => 'Guardamos el compromiso por :valor para el :fecha.',
        'actualizado' => 'Actualizamos el compromiso.',
        'cumplido' => 'Marcamos el compromiso como cumplido.',
        'anulado' => 'Anulamos el compromiso.',
    ],

    'errores' => [
        'cerrado' => 'Este compromiso ya no se puede cambiar: otra persona lo cerró o ya estaba cerrado. Recarga la página para ver cómo quedó.',
        'valor' => 'Escribe un valor en pesos mayor que cero, por ejemplo 5.000.000.',
        'motivo' => 'Escribe por qué anulas el compromiso.',
        'nota' => 'No pudimos guardar la nota. Revisa que no sea muy larga.',
    ],

];
