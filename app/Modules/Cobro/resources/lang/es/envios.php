<?php

// Runs of collection mail: preview, test send, console and errors.
return [

    'ejemplo' => [
        'cliente' => 'Cliente de ejemplo SAS',
    ],

    'vista' => [
        'descripcion' => [
            'cobro_semanal' => 'Así queda el próximo envío a los clientes con facturas vencidas. Revisa quién lo recibe, abre el correo de cada cliente y envíate una prueba.',
            'circularizacion' => 'Así queda la circularización del próximo cierre. El formato es provisional: se reemplazará por el informe que hoy reciben los clientes.',
        ],
        'empresas' => 'Empresas',
        'proximo' => 'Próximo envío',
        'proximo_detalle' => ':fecha a las :hora (hora de Colombia)',
        'pausado' => 'Pausado mientras el formato sea un borrador',
        'a_enviar' => 'Recibirán el correo',
        'sin_correo' => 'Sin dirección de correo',
        'excluidos' => 'Excluidos',
        'valor' => 'Valor a cobrar',
        'borrador' => [
            'titulo' => 'Formato en borrador',
            'detalle' => 'Este envío no sale en su fecha programada hasta que llegue el formato definitivo. Puedes revisarlo y enviarte una prueba.',
        ],
        'filtros' => 'Filtrar clientes del envío',
        'todos' => 'Todos',
        'tabla' => 'Clientes del próximo envío',
        'columnas' => [
            'cliente' => 'Cliente',
            'correos' => 'Se envía a',
            'facturas' => 'Facturas',
            'valor' => 'Valor',
            'estado' => 'Estado',
            'acciones' => 'Acciones',
        ],
        'ver_correo' => 'Ver correo',
        'ver_correo_de' => 'de :cliente',
        'vacio' => [
            'titulo' => 'Nadie recibiría este envío',
            'detalle' => 'Ningún cliente de esta empresa tiene facturas para este correo en la última cartera.',
        ],
        'sin_empresas' => [
            'titulo' => 'Este envío no aplica a tus empresas',
            'detalle' => 'Por ahora solo se envía para las empresas configuradas.',
        ],
        'ultimos' => 'Envíos anteriores',
        'ver_informes' => 'Ver informes de envío',
    ],

    'prueba' => [
        'titulo' => 'Enviarme una prueba',
        'descripcion' => 'Te llega el correo de un cliente del envío, marcado como prueba. No se le envía nada al cliente.',
        'cliente' => 'Cliente de la prueba',
        'enviar' => 'Enviarme la prueba',
        'enviada' => 'Te enviamos la prueba a :correo.',
        'sin_correo_usuario' => 'Tu usuario no tiene correo para recibir la prueba.',
        'sin_datos' => 'Ese cliente ya no tiene facturas para este correo.',
        'fallo' => 'No se pudo enviar la prueba. Revisa la conexión con Microsoft 365 o inténtalo más tarde.',
    ],

    'bitacora' => [
        'enviado' => 'Correo de :tipo enviado a :correos',
    ],

    'errores' => [
        'inesperado' => 'Error inesperado al preparar el correo. Revisa el registro de errores.',
        'no_aceptado' => 'Microsoft 365 no aceptó el correo.',
        'sin_configurar' => 'La conexión con Microsoft 365 no está configurada.',
        'sin_respuesta' => 'Microsoft 365 no respondió a tiempo.',
        'limite' => 'Microsoft 365 frenó el envío por demasiados correos por minuto.',
        'permisos' => 'Microsoft 365 no dio acceso al buzón de la empresa. Revisa los permisos de la aplicación.',
        'destinatario' => 'Microsoft 365 rechazó la dirección de correo del cliente.',
        'codigo' => 'Microsoft 365 no aceptó el correo (código :codigo).',
        'interrumpido' => 'El envío se interrumpió y no se sabe si salió. Revisa los elementos enviados del buzón.',
    ],

    'consola' => [
        'borrador' => ':tipo: el formato aún es un borrador, no se envía nada. Usa --aun-en-borrador para probar el motor.',
        'ya_existe' => ':empresa: el envío de :tipo de esta fecha ya se preparó; no se envía de nuevo.',
        'iniciado' => ':empresa: :tipo en cola para :en_cola clientes; :sin_correo sin correo y :excluidos excluidos.',
        'empresa_invalida' => 'La empresa :empresa no tiene este envío. Empresas válidas: :validas.',
        'interrumpidos' => '{0} No hay envíos interrumpidos.|{1} Se cerró 1 envío interrumpido.|[2,*] Se cerraron :total envíos interrumpidos.',
    ],

];
