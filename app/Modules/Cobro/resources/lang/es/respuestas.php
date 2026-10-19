<?php

// Replies and bounces read from the company inboxes.
return [

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
