<?php

// Detail of a receipt (GET /soportes/{soporte}), read with
// __('soportes::detalle.<key>').
return [

    'contexto' => 'Soportes de pago',
    'volver' => 'Volver a los soportes',

    'datos' => [
        'titulo' => 'Datos del pago',
        'cliente' => 'Cliente',
        'cliente_valor' => ':nombre · código :codigo',
        'empresa' => 'Empresa',
        'valor' => 'Valor pagado',
        'fecha' => 'Fecha de pago',
        'metodo' => 'Medio de pago',
        'sin_metodo' => 'Sin especificar',
        'nota' => 'Nota',
        'sin_nota' => 'Sin nota',
        'cargado_por' => 'Cargado por',
    ],

    'estado' => [
        'titulo' => 'Revisión de cartera',
        'estado' => 'Estado',
        'pendiente' => 'Cartera todavía no ha revisado este soporte.',
        'revisado_por' => 'Revisado por',
        'recibo' => 'Número de recibo de caja',
        'sin_recibo' => 'Sin número',
        'motivo' => 'Motivo del rechazo',
    ],

    'facturas' => [
        'titulo' => 'Facturas que cubre el pago',
        'descripcion' => 'Como estaban cuando se cargó el soporte.',
        'tabla' => 'Facturas del soporte',
        'columnas' => [
            'documento' => 'Documento',
            'tipo' => 'Tipo',
            'vencimiento' => 'Vencimiento',
            'dias' => 'Días de mora',
            'saldo' => 'Saldo',
        ],
    ],

    'archivos' => [
        'titulo' => 'Comprobantes',
        'descargar' => 'Descargar :nombre',
        'descargar_corto' => 'Descargar',
        'ver_pdf' => 'Ver PDF',
        'ver_pdf_de' => 'Ver :nombre en otra pestaña',
        'imagen' => 'Comprobante :nombre',
        'peso' => ':kb KB',
    ],

];
