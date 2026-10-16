<?php

return [
    'boton' => 'Exportar a Excel',

    'lista' => [
        'hoja' => 'Clientes',
        'archivo' => 'clientes-cartera',
        'columnas' => [
            'empresa' => 'Empresa',
            'codigo' => 'Código SAP',
            'cliente' => 'Cliente',
            'nit' => 'NIT',
            'estado' => 'Estado',
            'total' => 'Total',
            'vencido' => 'Vencido',
            'dias' => 'Días de mora',
            'asesor' => 'Asesor',
            'gestor' => 'Gestor responsable',
            'ultima_gestion' => 'Última gestión',
        ],
    ],

    'ficha' => [
        'archivo' => 'cliente',
        'si' => 'Sí',
        'sistema' => 'Sistema',

        'datos' => [
            'hoja' => 'Cliente',
            'cliente' => 'Cliente',
            'empresa' => 'Empresa',
            'codigo' => 'Código SAP',
            'nit' => 'NIT',
            'estado' => 'Estado',
            'grupo' => 'Grupo',
            'ciudad' => 'Ciudad',
            'condicion' => 'Condición de pago',
            'cupo' => 'Cupo',
            'total' => 'Total',
            'vencido' => 'Vencido',
            'dias' => 'Días de mora máxima',
            'asesor' => 'Asesor',
            'gestor' => 'Gestor responsable',
        ],

        'facturas' => [
            'hoja' => 'Facturas abiertas',
            'columnas' => [
                'documento' => 'Documento',
                'emision' => 'Emisión',
                'vence' => 'Vence',
                'dias' => 'Días de mora',
                'valor' => 'Valor original',
                'saldo' => 'Saldo',
            ],
        ],

        'contactos' => [
            'hoja' => 'Contactos',
            'columnas' => [
                'nombre' => 'Nombre',
                'cargo' => 'Cargo',
                'correo' => 'Correo',
                'telefono' => 'Teléfono',
                'principal' => 'Principal',
            ],
        ],

        'compromisos' => [
            'hoja' => 'Compromisos',
            'columnas' => [
                'vence' => 'Vence',
                'valor' => 'Valor',
                'estado' => 'Estado',
                'registrado_por' => 'Registrado por',
                'notas' => 'Notas',
            ],
        ],

        'bitacora' => [
            'hoja' => 'Bitácora',
            'columnas' => [
                'fecha' => 'Fecha y hora',
                'tipo' => 'Tipo',
                'resumen' => 'Resumen',
                'resultado' => 'Resultado',
                'detalle' => 'Detalle',
                'usuario' => 'Usuario',
            ],
        ],
    ],
];
