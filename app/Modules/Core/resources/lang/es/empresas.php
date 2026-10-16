<?php

// Company texts, read with __('core::empresas.<key>'): the company selector
// and the Parámetros screen (admins).
return [

    'campo' => 'empresa',
    'grupo' => 'Grupo RYR',
    'cambiada' => 'Ahora trabajas en :empresa.',
    'no_disponible' => 'No tienes acceso a esa empresa.',
    'grupo_no_disponible' => 'Necesitas acceso a las dos empresas para ver el Grupo RYR.',

    'parametros' => [
        'titulo' => 'Parámetros',
        'descripcion' => 'Los días de gracia y la ventana de «Por vencer» de cada empresa. Con ellos se calcula el estado automático de sus clientes.',
        'tabla' => 'Parámetros de cada empresa',
        'empresa' => ':nombre (:codigo)',
        'dias' => ':count día|:count días',

        'columnas' => [
            'empresa' => 'Empresa',
            'dias_gracia' => 'Días de gracia',
            'ventana' => 'Ventana de «Por vencer»',
            'acciones' => 'Acciones',
        ],

        'acciones' => [
            'editar' => 'Editar',
            'de' => 'los parámetros de :empresa',
        ],

        // Field names in the validation messages.
        'campos' => [
            'dias_gracia' => 'días de gracia',
            'ventana' => 'ventana de «Por vencer»',
        ],

        'formulario' => [
            'descripcion' => 'Al guardar, recalculamos el estado automático de sus clientes con la última carga de cartera.',
            'dias_gracia' => 'Días de gracia',
            'dias_gracia_ayuda' => 'Días después del vencimiento antes de que una factura cuente como vencida para el estado del cliente.',
            'ventana' => 'Ventana de «Por vencer»',
            'ventana_ayuda' => 'Días antes del vencimiento en los que una factura ya pone al cliente «Por vencer».',
            'unidad' => 'días',
            'guardar' => 'Guardar',
            'cancelar' => 'Cancelar',
        ],

        'mensajes' => [
            'guardados' => 'Guardamos los parámetros de :empresa. Los estados de sus clientes se recalculan en unos minutos.',
            'sin_cambios' => 'Los parámetros de :empresa no cambiaron.',
        ],
    ],

];
