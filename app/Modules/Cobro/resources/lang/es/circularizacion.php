<?php

// Circularization (4.2): console messages of cobro:circularizacion, and the
// fixed blocks of the Segurex statement mail, word for word as the company
// sends it today (client-facing: they keep "usted" and the capitals of the
// original).
return [

    // A company held back because its loaded cartera is not the month close.
    'consola' => [
        'sin_cierre' => 'La circularización de :empresa no se envió: la cartera cargada es del :corte y el cierre del mes es el :cierre. '
            .'Cuando cargues el cierre, ejecuta php artisan cobro:circularizacion --empresa=:codigo. '
            .'Si ya no hay una carga del cierre, agrega --con-corte-actual para enviarla con el último corte.',
        'sin_cartera' => 'La circularización de :empresa no se envió: no hay cartera cargada y el cierre del mes es el :cierre. '
            .'Cuando cargues el cierre, ejecuta php artisan cobro:circularizacion --empresa=:codigo.',
    ],

    'segurex' => [
        // Under the invoice table. :DIA_MES, :MES_SIGUIENTE and :MES_CORTE
        // are written in capitals.
        'aviso_cierre' => '"ACLARAMOS QUE ESTE ESTADO DE CARTERA CORRESPONDE AL CIERRE DEL :DIA_MES. SI REALIZÓ EL PAGO DURANTE EL MES DE :MES_SIGUIENTE, '
            .'TENGA EN CUENTA QUE DICHO PAGO NO SE VE REFLEJADO EN ESTA CIRCULARIZACIÓN, YA QUE LA INFORMACIÓN CORRESPONDE EXCLUSIVAMENTE AL CIERRE DE '
            .':MES_CORTE. AGRADECEMOS SU ATENCIÓN."',

        // Signature footer after the closing text.
        'pie' => [
            'empresa' => 'SEGUREX LATAM S.A.S.',
            'direccion' => 'Calle 25D No. 95-45',
            'ciudad' => 'Bogotá, D.C. - Colombia',
            'movil' => 'Móvil: +3153654788',
            'pbx' => 'PBX: +57 (60-1) 3713766 Ext. 141',
            'correo' => 'cartera@segurex.com',
            'web' => 'www.segurex.com',
            'web_url' => 'https://www.segurex.com',
            'ambiente' => 'Antes de imprimir, piense en su responsabilidad con el Medio Ambiente.',
            'aviso_legal_titulo' => 'AVISO LEGAL:',
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
        ],
    ],

];
