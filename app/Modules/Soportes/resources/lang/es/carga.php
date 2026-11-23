<?php

// Upload of a payment receipt (GET /soportes/nuevo and POST /soportes),
// read with __('soportes::carga.<key>').
return [

    'contexto' => 'Soportes de pago',
    'titulo' => 'Cargar soporte de pago',

    'clientes' => [
        'descripcion' => 'Busca el cliente que pagó. Solo aparecen clientes con facturas vencidas que puedes gestionar.',
        'buscar' => [
            'etiqueta' => 'Buscar cliente',
            'campo' => 'Nombre o NIT',
            'aplicar' => 'Buscar',
            'limpiar' => 'Limpiar',
        ],
        'tabla' => 'Clientes con facturas vencidas',
        'columnas' => [
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'nit' => 'NIT',
            'vencido' => 'Saldo vencido',
            'dias' => 'Días de mora',
        ],
        'vacio' => [
            'titulo' => 'No encontramos clientes con facturas vencidas',
            'detalle' => 'Prueba con otro nombre o NIT.',
        ],
    ],

    'formulario' => [
        'descripcion' => 'Marca las facturas vencidas que cubre el pago y adjunta el comprobante. El soporte queda pendiente hasta que cartera lo revise.',
        'cliente' => 'NIT :nit · código :codigo · :empresa',
        'cambiar' => 'Cambiar cliente',
        'facturas' => [
            'titulo' => 'Facturas que cubre el pago',
            'descripcion' => 'Solo se muestran las facturas vencidas con saldo.',
            'tabla' => 'Facturas vencidas del cliente',
            'columnas' => [
                'documento' => 'Documento',
                'tipo' => 'Tipo',
                'vencimiento' => 'Vencimiento',
                'dias' => 'Días de mora',
                'saldo' => 'Saldo',
            ],
            'cuotas' => '{2} 2 cuotas vencidas|[3,*] :count cuotas vencidas',
            'vacio' => [
                'titulo' => 'Este cliente no tiene facturas vencidas',
                'detalle' => 'Solo puedes cargar soportes de pago de facturas vencidas.',
            ],
        ],
        'pago' => [
            'titulo' => 'Datos del pago',
        ],
        'valor' => 'Valor pagado',
        'valor_ayuda' => 'En pesos, sin puntos de miles. Usa punto para los centavos.',
        'fecha' => 'Fecha de pago',
        'metodo' => 'Medio de pago',
        'metodo_vacio' => 'Sin especificar',
        'nota' => 'Nota',
        'nota_ayuda' => 'Opcional. Lo que cartera deba saber para aplicar el pago.',
        'archivos' => 'Comprobantes',
        'archivos_ayuda' => 'De 1 a :max archivos PDF, JPG o PNG, hasta :mb MB cada uno.',
        'enviar' => 'Cargar soporte',
        'cancelar' => 'Cancelar',
    ],

    'atributos' => [
        'cliente' => 'cliente',
        'facturas' => 'facturas',
        'facturas.*' => 'factura',
        'valor' => 'valor pagado',
        'fecha_pago' => 'fecha de pago',
        'metodo' => 'medio de pago',
        'nota' => 'nota',
        'archivos' => 'comprobantes',
        'archivos.*' => 'comprobante',
    ],

    'validacion' => [
        'facturas' => 'Marca al menos una factura vencida.',
        'factura_repetida' => 'Marcaste la misma factura dos veces.',
        'valor' => 'Escribe el valor en pesos, sin puntos de miles y con máximo dos decimales.',
        'valor_positivo' => 'El valor pagado debe ser mayor que cero.',
        'fecha_futura' => 'La fecha de pago no puede ser posterior a hoy.',
        'archivos' => 'Adjunta al menos un comprobante.',
        'archivos_max' => 'Adjunta máximo :max comprobantes.',
        'formato' => 'Cada comprobante debe ser un PDF, JPG o PNG.',
        'peso' => 'Cada comprobante puede pesar hasta :mb MB.',
    ],

    // Refusals of UploadReceipt (ReceiptRefused::$reason).
    'errores' => [
        'cliente_no_encontrado' => 'No encontramos ese cliente entre los que puedes gestionar.',
        'factura_no_vencida' => 'Alguna de las facturas marcadas ya no está vencida o no es de este cliente. Revisa la lista.',
        'archivo_no_aceptado' => 'Cada comprobante debe ser un PDF, JPG o PNG.',
    ],

    'resultado' => [
        'cargado' => 'Soporte cargado. Queda pendiente de revisión.',
    ],

];
