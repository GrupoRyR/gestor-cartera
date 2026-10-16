<?php

return [

    'boton' => [
        'excel' => 'Descargar Excel',
        'pdf' => 'Descargar PDF',
        'whatsapp' => 'Enviar por WhatsApp',
    ],

    'whatsapp' => [
        'sin_celular' => 'No tenemos un celular de este cliente: en WhatsApp eliges a quién enviarlo.',
        'ayuda' => 'Se abre WhatsApp con el mensaje listo. Lo revisas y lo envías tú.',
    ],

    'hojas' => [
        'clientes' => 'Clientes',
        'facturas' => 'Documentos',
    ],

    'clientes' => [
        'codigo' => 'Código',
        'cliente' => 'Cliente',
        'nit' => 'NIT',
        'empresa' => 'Empresa',
        'asesor' => 'Asesor',
        'estado' => 'Estado',
        'saldo' => 'Saldo (COP)',
        'vencido' => 'Vencido (COP)',
        'dias' => 'Días de mora',
        'gestion' => 'Última gestión',
    ],

    'facturas' => [
        'codigo' => 'Código cliente',
        'cliente' => 'Cliente',
        'nit' => 'NIT',
        'asesor' => 'Asesor',
        'documento' => 'Documento',
        'tipo' => 'Tipo',
        'emision' => 'Emisión',
        'vencimiento' => 'Vencimiento',
        'dias' => 'Días (negativo: por vencer)',
        'valor' => 'Valor (COP)',
        'saldo' => 'Saldo (COP)',
        'edad' => 'Antigüedad',
        'recortado' => 'Aquí solo están los documentos de los primeros :limite clientes. Filtra por asesor o por estado para sacar el resto.',
    ],

    'archivo' => [
        'cartera' => 'cartera-por-cliente',
        'cliente' => 'cartera-:codigo',
        'estado_cuenta' => 'estado-de-cuenta-:codigo',
    ],

    'pdf' => [
        'titulo' => 'Estado de cuenta',
        'cliente' => 'Cliente',
        'nit' => 'NIT',
        'codigo' => 'Código',
        'asesor' => 'Asesor',
        'condicion' => 'Condición de pago',
        'corte' => 'Corte de cartera',
        'generado' => 'Generado',
        'resumen' => 'Resumen',
        'saldo' => 'Saldo total',
        'sin_vencer' => 'Sin vencer',
        'vencido' => 'Vencido',
        'mas_90' => 'Más de 90 días',
        'edades' => 'Antigüedad del saldo',
        'documentos' => 'Documentos abiertos',
        'sin_documentos' => 'Este cliente no tiene documentos abiertos.',
        'columnas' => [
            'documento' => 'Documento',
            'tipo' => 'Tipo',
            'emision' => 'Emisión',
            'vence' => 'Vence',
            'dias' => 'Días',
            'valor' => 'Valor',
            'saldo' => 'Saldo',
        ],
        'pie' => 'Documento generado desde el gestor de cartera de :empresa el :fecha. Las cifras son las del corte indicado.',
    ],

];
