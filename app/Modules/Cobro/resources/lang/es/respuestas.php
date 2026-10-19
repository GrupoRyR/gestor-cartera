<?php

// Replies and bounces read from the company inboxes.
return [

    'titulo' => 'Respuestas de clientes',
    'descripcion' => 'Lo que los clientes responden a los correos de cobro y los correos que rebotaron. Márcalos como leídos cuando los gestiones.',

    'filtros' => [
        'etiqueta' => 'Mostrar mensajes',
        'pendientes' => 'Pendientes',
        'leidas' => 'Leídas',
        'todas' => 'Todas',
    ],

    'tabla' => 'Mensajes recibidos',
    'columnas' => [
        'mensaje' => 'Mensaje',
        'cliente' => 'Cliente',
        'empresa' => 'Empresa',
        'recibido' => 'Recibido',
        'estado' => 'Estado',
    ],
    'sin_cliente' => 'Sin cliente identificado',
    'sin_asunto' => '(sin asunto)',
    'rebote' => 'Rebote',
    'pendiente' => 'Pendiente',
    'leida' => 'Leída',
    'ver' => 'Ver',
    'ver_de' => 'el mensaje de :remitente',

    'acciones' => [
        'marcar_leidas' => 'Marcar como leídas',
        'marcar_pendientes' => 'Marcar como pendientes',
        'marcar_leida' => 'Marcar como leída',
        'marcar_pendiente' => 'Marcar como pendiente',
        'volver' => 'Volver a las respuestas',
        'ficha' => 'Ver ficha del cliente',
    ],

    'vacio' => [
        'pendientes' => [
            'titulo' => 'No tienes respuestas pendientes',
            'detalle' => 'Cuando un cliente responda un correo de cobro, aparecerá aquí.',
        ],
        'otros' => [
            'titulo' => 'No hay mensajes para mostrar',
            'detalle' => 'Prueba con otro filtro.',
        ],
    ],

    'detalle' => [
        'contexto' => 'Respuesta de cliente',
        'de' => 'De',
        'cliente' => 'Cliente',
        'recibido' => 'Recibido',
        'responde_a' => 'Responde a',
        'responde_a_detalle' => ':asunto, enviado el :fecha',
        'motivo_rebote' => 'Motivo del rebote',
        'correo_rebotado' => 'Correo que rebotó',
        'texto' => 'Texto del mensaje',
        'leida_por' => 'Leída por :nombre el :fecha',
        'sin_texto' => 'El mensaje no tiene texto.',
    ],

    'mensajes' => [
        'marca_uno' => 'Marca al menos un mensaje.',
        'leidas' => '{0} No se marcó ningún mensaje.|{1} Marcaste 1 mensaje como leído.|[2,*] Marcaste :total mensajes como leídos.',
        'pendientes' => '{0} No se marcó ningún mensaje.|{1} Marcaste 1 mensaje como pendiente.|[2,*] Marcaste :total mensajes como pendientes.',
    ],

    'bitacora' => [
        'respuesta' => 'Respuesta por correo de :remitente',
        'rebote' => 'Rebote del correo de cobro a :correo',
    ],

    'consola' => [
        'sin_graph' => 'Microsoft Graph no está configurado (GRAPH_TENANT_ID, GRAPH_CLIENT_ID y GRAPH_CLIENT_SECRET): no se leen los buzones.',
        'sincronizado' => ':empresa: :nuevos mensajes nuevos.',
        'fallo' => ':empresa: no se pudo leer el buzón. Revisa el registro de errores.',
    ],

];
