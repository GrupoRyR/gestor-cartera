<?php

// The «Ajustes y cruces» screen (§7.4): manual captures with support that
// another user approves.
return [
    'titulo' => 'Ajustes y cruces',
    'descripcion' => 'Ventas OBR, reintegros, descuentos y cruces que no llegan de SAP. Cada uno lleva su soporte y solo entra a la liquidación cuando otra persona lo aprueba.',

    'periodo' => [
        'etiqueta' => 'Elegir el período',
        'campo' => 'Período',
        'ver' => 'Ver',
    ],

    'pestanas' => [
        'etiqueta' => 'Tipos de ajuste',
        'obr' => 'OBR / Escudos',
        'reintegros' => 'Reintegros y descuentos',
        'cruces' => 'Cruces manuales',
        'reasignaciones' => 'Reasignaciones de cruces',
    ],

    'sin_codigos' => 'No hay parámetros vigentes en :periodo, así que no hay códigos a los que registrarles ajustes.',

    'estados' => [
        'pendiente' => 'Pendiente',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado',
    ],

    'lista' => [
        'vacio' => 'Aún no hay registros de este tipo en :periodo.',
        'columnas' => [
            'estado' => 'Estado',
            'registro' => 'Registrado',
            'revision' => 'Revisado',
            'soporte' => 'Soporte',
            'acciones' => 'Acciones',
        ],
        'por' => ':fecha por :nombre',
        'motivo_rechazo' => 'Motivo: :motivo',
        'sin_revisar' => 'Sin revisar',
        'ver_soporte' => 'Ver soporte',
        'ver_soporte_de' => 'Ver el soporte del registro :id',
        'sin_soporte' => 'Sin soporte',
    ],

    'formulario' => [
        'soporte' => 'Soporte',
        'soporte_ayuda' => 'PDF, JPG, PNG, XLSX o CSV de hasta 10 MB.',
        'cancelar' => 'Cancelar',
        'guardar' => 'Registrar',
    ],

    'obr' => [
        'titulo' => 'Ventas OBR / Escudos',
        'caption' => 'Ventas OBR / Escudos del período',
        'nuevo' => 'Registrar venta OBR',
        'codigo' => 'Código de vendedor',
        'codigo_elige' => 'Elige el código',
        'descripcion' => 'Descripción',
        'valor_vendido' => 'Valor vendido',
        'tasa' => 'Tasa (%)',
        'tasa_ayuda' => 'Porcentaje de comisión, por ejemplo 2,5.',
        'columnas' => [
            'codigo' => 'Código',
            'descripcion' => 'Descripción',
            'valor_vendido' => 'Valor vendido',
            'tasa' => 'Tasa',
        ],
    ],

    'reintegros' => [
        'titulo' => 'Reintegros y descuentos',
        'caption' => 'Reintegros y descuentos del período',
        'nuevo' => 'Registrar reintegro o descuento',
        'codigo' => 'Código de vendedor',
        'codigo_elige' => 'Elige el código',
        'tipo' => 'Tipo',
        'tipo_elige' => 'Elige el tipo',
        'tipos' => [
            'reintegro' => 'Reintegro (suma)',
            'descuento' => 'Descuento (resta)',
        ],
        'valor' => 'Valor',
        'descripcion' => 'Descripción',
        'columnas' => [
            'codigo' => 'Código',
            'tipo' => 'Tipo',
            'valor' => 'Valor',
            'descripcion' => 'Descripción',
        ],
    ],

    'mensajes' => [
        'creado' => 'Registraste :que de :periodo. Queda pendiente hasta que otra persona lo apruebe.',
        'que' => [
            'obr' => 'la venta OBR',
            'ajuste' => 'el ajuste',
            'cruce' => 'el cruce manual',
            'reasignacion' => 'la reasignación',
        ],
    ],

    'validacion' => [
        'periodo' => 'período',
        'codigo' => 'código de vendedor',
        'descripcion' => 'descripción',
        'valor_vendido' => 'valor vendido',
        'tasa' => 'tasa',
        'tipo' => 'tipo',
        'valor' => 'valor',
        'soporte' => 'soporte',
        'valor_invalido' => 'Escribe el valor como 1234567 o 1.234.567.',
        'valor_no_positivo' => 'El valor debe ser mayor que cero.',
        'tasa_invalida' => 'Escribe la tasa como un porcentaje mayor que cero y hasta 100, por ejemplo 2,5.',
        'codigo_no_vigente' => 'Elige un código con parámetros vigentes en el período.',
    ],
];
