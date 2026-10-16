<?php

return [
    'contexto' => ':empresa · NIT :nit',
    'volver' => 'Volver a clientes',
    'sin_dato' => 'Sin dato',

    'indicadores' => [
        'total' => 'Total',
        'vencido' => 'Vencido',
        'mora' => 'Mora máxima',
        'mora_valor' => '{0} Al día|{1} 1 día|[2,*] :count días',
        'cupo' => 'Cupo',
        'cupo_disponible' => 'Disponible: :valor',
        'cupo_excedido' => 'Excedido en :valor',
        'sin_cupo' => 'Sin cupo asignado',
    ],

    'datos' => [
        'titulo' => 'Datos del cliente',
        'estado' => 'Estado',
        'codigo' => 'Código en SAP',
        'nit' => 'NIT',
        'empresa' => 'Empresa',
        'grupo' => 'Grupo',
        'ciudad' => 'Ciudad',
        'condicion' => 'Condición de pago',
        'asesor' => 'Asesor',
        'gestor' => 'Gestor responsable',
        'sin_asesor' => 'Sin asesor',
        'sin_gestor' => 'Sin gestor asignado',
    ],

    'cambiar_estado' => [
        'titulo' => 'Cambiar el estado',
        'estado' => 'Estado',
        'nota' => 'Nota (opcional)',
        'nota_ayuda' => 'Queda en la bitácora junto al cambio.',
        'boton' => 'Cambiar estado',
    ],

    'contactos' => [
        'titulo' => 'Contactos',
        'descripcion' => 'Vienen de SAP; si alguno cambió, se corrige allá.',
        'principal' => 'Principal',
        'sin_nombre' => 'Contacto sin nombre',
        'vacio' => 'SAP no tiene contactos para este cliente.',
        'llamar' => 'Llamar a :nombre',
    ],

    'facturas' => [
        'titulo' => 'Facturas abiertas',
        'descripcion' => 'Lo que el cliente debe en la última carga, con la más antigua primero.',
        'tabla' => 'Facturas abiertas del cliente',
        'vacio' => [
            'titulo' => 'Sin facturas abiertas',
            'detalle' => 'El cliente no tiene saldo en la última carga.',
        ],
        'columnas' => [
            'documento' => 'Documento',
            'emision' => 'Emisión',
            'vence' => 'Vence',
            'dias' => 'Días',
            'valor' => 'Valor original',
            'saldo' => 'Saldo',
        ],
        'mora' => '{1} 1 día de mora|[2,*] :count días de mora',
        'vence_hoy' => 'Vence hoy',
        'por_vencer' => '{1} Vence en 1 día|[2,*] Vence en :count días',
        'total' => 'Saldo de las facturas abiertas',
    ],

    'compromisos' => [
        'titulo' => 'Compromisos de pago',
        'descripcion' => 'Las fechas en que el cliente prometió pagar.',
        'tabla' => 'Compromisos de pago del cliente',
        'vacio' => [
            'titulo' => 'Sin compromisos de pago',
            'detalle' => 'Cuando el cliente prometa una fecha de pago, aparecerá aquí.',
        ],
        'columnas' => [
            'vence' => 'Vence',
            'valor' => 'Valor',
            'estado' => 'Estado',
            'creado_por' => 'Registrado por',
            'notas' => 'Notas',
        ],
        'solo_lectura_sgx' => 'En SGX los compromisos los registra el asesor del cliente; aquí solo los ves.',
    ],

    'bitacora' => [
        'titulo' => 'Bitácora de gestión',
        'descripcion' => 'Llamadas, notas, descuentos y cambios, lo más reciente primero.',
        'vacio' => [
            'titulo' => 'Aún no hay gestiones',
            'detalle' => 'Lo que registres sobre este cliente quedará aquí.',
        ],
        'resultado' => 'Resultado',
        'por' => 'por :usuario',
        'sistema' => 'Registrado por el sistema',
        'eliminar' => 'Eliminar',
        'eliminar_de' => 'la gestión «:resumen»',
        'confirmar_eliminar' => '¿Eliminar esta gestión de la bitácora?',
    ],

    'respuestas' => [
        'titulo' => 'Respuestas de correo',
        'descripcion' => 'Lo que el cliente contestó a los correos de cobro, y los correos que rebotaron.',
        'no_disponible' => 'Las respuestas de correo aparecerán cuando se active el cobro por correo.',
        'vacio' => [
            'titulo' => 'Sin respuestas',
            'detalle' => 'El cliente no ha contestado ningún correo.',
        ],
        'rebote' => 'Rebotó',
        'rebote_motivo' => 'Motivo: :motivo',
        'sin_leer' => 'Sin leer',
        'de' => 'De :remitente',
        'abrir' => 'Abrir en el buzón',
    ],
];
