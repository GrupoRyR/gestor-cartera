<?php

// The reports of a closed settlement version (§7.7, §8.2, §10): the
// collection PDF of each code and of 85.1, the ZIP and the payroll file.
return [
    'titulo_pagina' => 'Informes de :mes',
    'descripcion' => 'Un PDF de comisión por recaudo por cada código con recaudos en el período. Se guardan una sola vez con su huella y ya no cambian.',

    'solo_cerrada' => 'Los informes se generan cuando la liquidación está cerrada.',
    'solo_cerrada_detalle' => 'Cierra la liquidación del período para habilitar los PDF y el archivo de nómina.',
    'sin_liquidacion' => 'Este período aún no tiene liquidación.',
    'ver_cerrada' => 'Ver la versión :version, la última cerrada',
    'ir_al_periodo' => 'Ir a la liquidación del período',

    'acciones' => [
        'generar' => 'Generar los informes pendientes',
        'zip' => 'Descargar todo en ZIP',
        'descargar' => 'Descargar',
        'descargar_de' => 'Descargar el informe :referencia',
    ],

    'recaudo' => [
        'titulo' => 'Informes de recaudo por comercial',
        'tabla' => 'Informes de comisión por recaudo de la versión',
        'vacio' => 'Ningún código tuvo recaudos en este período, así que no hay informes de recaudo.',
        'columnas' => [
            'codigo' => 'Código',
            'comercial' => 'Comercial',
            'transacciones' => 'Transacciones',
            'valor_pagado' => 'Valor pagado',
            'consolidado' => 'Comisión en el consolidado',
            'total_pdf' => 'Total del PDF',
            'cuadre' => 'Cuadre',
            'archivo' => 'Archivo guardado',
            'acciones' => 'Acciones',
        ],
        'cuadra' => 'Cuadra',
        'diferencia' => 'Diferencia de :valor',
        'pendiente' => 'Pendiente',
        'generado' => 'Generado el :fecha por :nombre',
        'huella' => 'Huella :huella',
    ],

    'mensajes' => [
        'generados' => '{1} Generaste 1 informe.|[0,*] Generaste :cantidad informes.',
        'ya_generados' => 'Todos los informes ya estaban generados; no cambiamos ninguno.',
        'no_cuadra' => '{1} No generamos el informe :referencias porque su total no coincide con la comisión de recaudo del consolidado (:detalle).|[0,*] No generamos los informes :referencias porque su total no coincide con la comisión de recaudo del consolidado (:detalle).',
        'diferencia' => ':referencia: PDF :pdf, consolidado :consolidado',
        'sin_generar' => 'Ese informe todavía no se ha generado. Pulsa «Generar los informes pendientes» primero.',
        'alterado' => 'El archivo guardado del informe :referencia no coincide con su huella o ya no está. No lo entregamos; avísale a TI.',
    ],

    // The payroll file (§8.1 annex, §6 step 6).
    'nomina' => [
        'titulo_tarjeta' => 'Archivo para nómina',
        'descripcion' => 'Cada persona con los roles por los que cobra y su valor pago nómina. Se genera con «Generar los informes pendientes» y después siempre se entrega la misma copia.',
        'descargar' => 'Descargar el archivo de nómina',
        'hoja' => 'Nómina',
        'titulo' => 'Archivo de nómina — :mes',
        'columnas' => [
            'persona' => 'Persona',
            'tipo' => 'Tipo',
            'referencia' => 'Referencia',
            'concepto' => 'Concepto',
            'valor' => 'Valor',
        ],
        'valor_pago' => 'Valor pago nómina',
        'total' => 'Total nómina',
    ],

    // The collection PDF (§8.2).
    'pdf' => [
        'titulo' => 'Informe de comisión por recaudo',
        'comercial' => 'Comercial: :nombre',
        'periodo' => 'Período: :mes',
        'generado' => 'Generado el :fecha',
        'version' => 'Liquidación versión :version',
        'nota_especial' => 'Comisión especial sobre el recaudo del código :codigo (:nombre) — tasa fija :tasa, no aplican tramos de días',
        'columnas' => [
            'vendedor' => 'Vendedor',
            'codigo' => 'Código',
            'factura' => 'Factura',
            'codigo_cliente' => 'Código cliente',
            'razon_social' => 'Razón social',
            'fecha_factura' => 'Fecha factura',
            'fecha_pago' => 'Fecha pago',
            'importe_original' => 'Importe original',
            'dias' => 'Días',
            'valor_pagado' => 'Valor pagado',
            'comision' => 'Comisión',
        ],
        'total' => 'Total',
        'transacciones' => 'Número de transacciones: :cantidad',
    ],
];
