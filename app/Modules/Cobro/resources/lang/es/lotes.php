<?php

// Delivery report: runs of collection mail and their clients.
return [

    'titulo' => 'Informes de envío',
    'descripcion' => 'Resultado de cada envío de cobro: a quién le llegó, qué rebotó y quién no tiene correo.',

    'filtros' => [
        'etiqueta' => 'Tipo de envío',
        'todos' => 'Todos',
    ],

    'tabla' => 'Envíos de cobro',
    'columnas' => [
        'fecha' => 'Fecha',
        'tipo' => 'Envío',
        'empresa' => 'Empresa',
        'estado' => 'Estado',
        'enviados' => 'Enviados',
        'rebotados' => 'Rebotados',
        'sin_correo' => 'Sin correo',
        'excluidos' => 'Excluidos',
        'fallidos' => 'No enviados',
        'acciones' => 'Acciones',
    ],
    'ver' => 'Ver informe',
    'ver_de' => 'del :fecha',
    'vacio' => [
        'titulo' => 'Aún no hay envíos',
        'detalle' => 'Aquí verás el resultado de cada cobro semanal después del miércoles.',
    ],

    'detalle' => [
        'titulo' => ':tipo del :fecha',
        'contexto' => 'Informe de envío',
        'volver' => 'Volver a los informes',
        'empresa' => 'Empresa',
        'corte' => 'Cartera con corte al',
        'iniciado' => 'Inició',
        'terminado' => 'Terminó',
        'en_curso' => 'Todavía se están enviando correos. Recarga la página en unos minutos.',
        'por' => 'Lo inició :nombre',
        'programado' => 'Envío programado',
        'filtros' => 'Filtrar clientes por resultado',
        'todos' => 'Todos',
        'tabla' => 'Clientes del envío',
        'columnas' => [
            'cliente' => 'Cliente',
            'correos' => 'Correos',
            'valor' => 'Valor',
            'estado' => 'Resultado',
            'detalle' => 'Detalle',
            'hora' => 'Enviado',
        ],
        'rebote' => 'Rebotó :correo: :motivo',
        'rebote_sin_correo' => 'Rebotó: :motivo',
        'vacio' => 'No hay clientes con este resultado.',
    ],

];
