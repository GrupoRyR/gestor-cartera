<?php

// Who signs each company's Cobro mails.
return [

    'titulo' => 'Firma del correo',
    'descripcion' => 'Los datos de quien firma el cobro semanal, la circularización y las respuestas de cada empresa. Cámbialos cuando otra persona lleve la cartera; la razón social, la dirección, el PBX y el correo de la empresa no cambian.',
    'empresas' => 'Empresas',

    'estado' => [
        'por_defecto' => 'Datos por defecto',
        'editada' => 'Editada por :nombre el :fecha',
    ],

    'campos' => [
        'nombre' => 'Nombre',
        'cargo' => 'Cargo',
        'movil' => 'Móvil',
        'ayuda_movil' => 'Con el indicativo si quieres, por ejemplo +57 315 365 4788.',
        'extension' => 'Extensión del PBX',
        'ayuda_extension' => 'Solo el número. Déjala vacía si no tiene extensión.',
    ],

    'acciones' => [
        'guardar' => 'Guardar la firma',
        'restaurar' => 'Volver a los datos por defecto',
    ],

    'mensajes' => [
        'guardada' => 'Guardaste la firma de :empresa.',
        'restaurada' => 'La firma de :empresa volvió a los datos por defecto.',
    ],

    // The signature at the end of each company's mails (cobro::correos.firma):
    // the person comes from /cobro/firma; this is the fixed company part,
    // word for word as each company signs today (client-facing: "usted").
    'correo' => [
        'despedida' => 'Cordialmente,',
        'movil' => 'Móvil: :movil',
        'pbx' => 'PBX: :pbx',
        'pbx_extension' => 'PBX: :pbx Ext. :extension',
        'SGX' => [
            'empresa' => 'SEGUREX LATAM S.A.S.',
            'direccion' => 'Calle 25D No. 95-45',
            'ciudad' => 'Bogotá, D.C. - Colombia',
            'pbx' => '+57 (60-1) 3713766',
            'correo' => 'cartera@segurex.com',
            'web' => 'www.segurex.com',
            'web_url' => 'https://www.segurex.com',
            'ambiente' => 'Antes de imprimir, piense en su responsabilidad con el Medio Ambiente.',
            'aviso_legal_titulo' => 'Aviso legal:',
            // Followed by the aviso_legal_correo link and a period.
            'aviso_legal' => 'Este mensaje es solamente para la persona a la que va dirigido. Puede contener información confidencial o legalmente protegida. '
                .'Ni la confidencialidad o privilegio alguno se pierde por cualquier transmisión errónea. Por tanto, si usted ha recibido este mensaje por '
                .'error, por favor elimine de su sistema inmediatamente este mensaje, así como todas las copias físicas e informe de esto al remitente. '
                .'No debe, directa o indirectamente, usar, revelar, distribuir, imprimir o copiar ninguna de las partes de este mensaje si usted no es el '
                .'destinatario final. Cualquier opinión expresada en este mensaje pertenecen exclusivamente al remitente, excepto cuando el mensaje '
                .'establezca lo contrario y el remitente esté autorizado para establecer que dichas opiniones son de SEGUREX LATAM S.A.S. Tenga en cuenta '
                .'que el correo electrónico vía Internet no permite asegurar ni la confidencialidad de los mensajes que se transmiten ni la correcta '
                .'recepción de estos. En caso de que el destinatario de este mensaje no apruebe el uso del correo electrónico vía Internet, por favor '
                .'hágalo saber inmediatamente al correo electrónico:',
            'aviso_legal_correo' => 'ventas@segurex.com',
            'imagenes' => [
                'sgx-redes' => 'Facebook, Instagram y YouTube: /SegurexCo',
                'sgx-logo' => 'Segurex',
            ],
        ],
        'SDC' => [
            'empresa' => 'SOLUCIONES Y DERIVADOS DE COLOMBIA S.A.S.',
            'direccion' => 'Km. 5 Vía Cajicá – Zipaquirá, Código Postal 250247',
            'ciudad' => 'Cajicá, Colombia',
            'pbx' => '+57 (60-1) 3713766',
            'correo' => 'cartera@derivadoscol.com',
            'imagenes' => [
                'sdc' => 'Diablo Rojo: ¡destapa cañerías al instante! Soluciones y Derivados de Colombia S.A.S.',
            ],
        ],
    ],

    'validacion' => [
        'nombre' => 'Escribe el nombre de quien firma.',
        'cargo' => 'Escribe el cargo de quien firma.',
        'largo' => 'Usa hasta 100 caracteres.',
        'movil' => 'Escribe un móvil válido, por ejemplo +57 315 365 4788.',
        'extension' => 'La extensión solo lleva números, hasta 6.',
    ],

];
