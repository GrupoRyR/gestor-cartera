<?php

return [

    'volver' => 'Volver a clientes',
    'contexto' => ':codigo · NIT :nit',
    'descripcion' => 'Asesor: :asesor · Condición de pago: :condicion',
    'sin_asesor' => 'sin asesor',
    'sin_condicion' => 'sin definir',

    'cupo_excedido' => [
        'titulo' => 'Este cliente superó su cupo de crédito',
        'detalle' => 'Debe :exceso más de lo aprobado. Conviene revisarlo antes de autorizar un nuevo despacho.',
    ],

    'kpis' => [
        'saldo' => 'Saldo total',
        'sin_vencer' => 'Sin vencer',
        'vencido' => 'Vencido',
        'cupo' => 'Cupo de crédito',
        'del_total' => ':porcentaje del total',
        'documentos' => '{0} Sin documentos abiertos|{1} 1 documento abierto|[2,*] :cantidad documentos abiertos',
        'disponible' => 'Disponible: :valor',
        'excedido' => 'Excedido en :valor',
        'sin_cupo' => 'Sin cupo asignado',
    ],

    'edades' => [
        'titulo' => 'Antigüedad de este cliente',
    ],

    'contactos' => [
        'titulo' => 'Contactos',
        'ayuda' => 'Las personas del cliente que tenemos en SAP.',
        'vacio' => 'Aún no tenemos contactos de este cliente.',
        'principal' => 'Principal',
        'sin_nombre' => 'Sin nombre',
    ],

    'facturas' => [
        'titulo' => 'Facturas pendientes',
        'tabla' => 'Facturas y otros documentos abiertos del cliente',
        'vacio' => 'Este cliente no tiene documentos abiertos.',
        'columnas' => [
            'documento' => 'Documento',
            'emision' => 'Emisión',
            'vence' => 'Vence',
            'estado' => 'Vencimiento',
            'valor' => 'Valor',
            'saldo' => 'Saldo',
            'edad' => 'Antigüedad',
        ],
        'dias' => [
            'vencida' => '{1} 1 día de mora|[2,*] :dias días de mora',
            'hoy' => 'Vence hoy',
            'por_vencer' => '{1} Vence mañana|[2,*] Vence en :dias días',
        ],
        // SAP TransType of the documents on the receivables accounts.
        'tipos' => [
            '13' => 'Factura',
            '14' => 'Nota crédito',
            '24' => 'Recibo de caja',
            '30' => 'Asiento',
        ],
    ],

    'compromisos' => [
        'titulo' => 'Compromisos de pago',
        'ayuda' => 'Lo que el cliente prometió pagar y para cuándo.',
        'vacio' => 'Aún no hay compromisos de pago de este cliente.',
        'por' => 'Registrado por :nombre el :fecha',
        'cumplido_el' => 'Cumplido el :fecha',
        'fecha' => 'Fecha de pago',
    ],

    'bitacora' => [
        'titulo' => 'Bitácora de gestiones',
        'ayuda' => 'Lo que se ha hecho con este cliente, de lo más reciente a lo más antiguo.',
        'vacio' => 'Todavía no hay gestiones registradas para este cliente.',
        'resultado' => 'Resultado: :resultado',
        'por' => ':nombre · :fecha',
        'plataforma' => 'Plataforma',
    ],

];
