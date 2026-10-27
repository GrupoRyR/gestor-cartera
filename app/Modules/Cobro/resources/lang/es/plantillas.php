<?php

// Mail templates: the texts each company edits, and their defaults.
return [

    'titulo' => 'Plantilla del cobro semanal',
    'descripcion' => 'Edita el asunto y los textos del correo de cobro de cada empresa. La tabla con las facturas abiertas del cliente se agrega sola.',
    'empresas' => 'Empresas',

    'campos' => [
        'asunto' => 'Asunto',
        'inicio' => 'Texto antes de la tabla de facturas',
        'cierre' => 'Texto después de la tabla de facturas',
        'ayuda_parrafos' => 'Deja una línea en blanco para separar párrafos. Escribe **así** para poner un texto en negrita.',
    ],

    'variables' => [
        'titulo' => 'Datos que puedes usar',
        'descripcion' => 'Escríbelos entre llaves y se reemplazan en cada correo.',
        'tabla' => 'Datos disponibles en la plantilla',
        'columnas' => [
            'dato' => 'Dato',
            'significa' => 'Qué muestra',
        ],
    ],

    'estado' => [
        'por_defecto' => 'Usa los textos por defecto',
        'editada' => 'Editada por :nombre el :fecha',
    ],

    'acciones' => [
        'guardar' => 'Guardar plantilla',
        'vista_previa' => 'Ver vista previa',
        'restaurar' => 'Volver a los textos por defecto',
    ],

    'mensajes' => [
        'guardada' => 'Guardaste la plantilla de :empresa.',
        'restaurada' => 'La plantilla de :empresa volvió a los textos por defecto.',
        'desconocidas' => 'Estos datos no existen: :variables. Revisa la lista de datos que puedes usar.',
    ],

    // Notice to IT and internal control when a template changes (audit S-08).
    'aviso' => [
        'asunto' => 'Cambió la plantilla del cobro semanal de :empresa',
        'titulo' => 'Cambió la plantilla del cobro semanal',
        'intro' => 'El cobro semanal sale solo, desde el buzón de la empresa, a todos los clientes con saldo vencido. Revisa que el cambio sea legítimo: un texto con otra cuenta para pagos o un enlace desconocido puede ser un fraude.',
        'empresa' => 'Empresa: :empresa',
        'quien' => 'Quién: :nombre (:correo)',
        'quien_desconocido' => 'Quién: un usuario que ya no existe',
        'cuando' => 'Cuándo: :fecha',
        'cambio' => 'Cambio: :cambio',
        'cambios' => [
            'updated' => 'edición de los textos',
            'reset' => 'volvió a los textos por defecto',
        ],
        'abrir' => 'Abrir la plantilla',
        'leyenda' => 'Las líneas con − se quitaron y las líneas con + se agregaron; las demás no cambiaron.',
        'pie' => 'Si no reconoces el cambio, corrige la plantilla y avisa a TI. Este aviso sale con cada cambio de la plantilla.',
    ],

    'validacion' => [
        'asunto' => 'asunto',
        'inicio' => 'texto antes de la tabla',
        'cierre' => 'texto después de la tabla',
    ],

    // Default texts of each RunKind, with {placeholders} and **bold**: per
    // company (<kind>.<company>) or shared by every company (<kind>).
    // The weekly collection texts are the ones each company sends today,
    // word for word (client-facing: they keep "usted" as written).
    'por_defecto' => [
        'cobro_semanal' => [
            'SGX' => [
                'asunto' => 'Cobro de cartera - Segurex LATAM SAS',
                'inicio' => "Buen día\n\n"
                    ."Estimado cliente **{cliente}**\n\n"
                    ."Espero que se encuentre bien.\n\n"
                    .'Nos dirigimos a usted para recordarte que su cuenta con **corte a {fecha_corte}** presenta un saldo pendiente por **{total_vencido}** '
                    ."correspondiente a {factura_o_facturas} **{numeros_facturas}** con **vencimiento el {vencimientos}**\n\n"
                    .'El saldo aún no ha sido abonado, y agradecemos su atención para normalización de su cuenta. Le solicitamos amablemente realizar el pago '
                    .'lo más pronto posible para evitar cargos adicionales o interrupciones en el servicio, si fuera aplicable. A continuación, le proporcionamos '
                    .'los detalles del estado de su cartera a la fecha:',
                'cierre' => 'Si ya realizó el pago, por favor ignore este cobro de cartera y envíenos el comprobante de pago a vuelta de correo. De igual manera, '
                    .'si presenta dudas o necesita información adicional, no dude en contactarnos a través de los medios autorizados como lo son los correos '
                    ."cartera@segurex.com y miguel.martinez@segurex.com o a la línea celular y WhatsApp 3176490246.\n\n"
                    ."Agradecemos su pronta atención a este asunto.\n\n"
                    ."**Cordialmente,**\n\n"
                    ."**María Paula Alvarez**\n"
                    .'**Auxiliar de Cartera**',
            ],
            'SDC' => [
                'asunto' => 'Cobro de cartera - Soluciones y Derivados de Colombia S.A.S',
                'inicio' => "Buen día\n\n"
                    ."Estimado cliente {cliente}\n\n"
                    ."Espero que se encuentre bien.\n\n"
                    .'**Nos dirigimos a usted para recordarte que su cuenta con corte a {fecha_corte} presenta un saldo pendiente por {total_vencido} '
                    ."correspondiente a {factura_o_facturas} {numeros_facturas} con vencimiento el {vencimientos}.**\n\n"
                    .'El saldo aún no ha sido abonado, y agradecemos su atención para normalización de su cuenta. Le solicitamos amablemente realizar el pago '
                    .'lo más pronto posible para evitar cargos adicionales o interrupciones en el servicio, si fuera aplicable. A continuación, le proporcionamos '
                    .'los detalles del estado de su cartera a la fecha:',
                'cierre' => 'Si ya realizó el pago, por favor ignore este cobro de cartera y envíenos el comprobante de pago a vuelta de correo. De igual manera, '
                    .'si presenta dudas o necesita información adicional, no dude en contactarnos a través de los medios autorizados como lo son los correos '
                    ."cartera@derivadoscol.com y miguel.martinez@derivadoscol.com o a la línea celular y WhatsApp 3176490246 o 3153654788.\n\n"
                    ."Agradecemos su pronta atención a este asunto.\n\n"
                    ."Cordialmente,\n\n"
                    ."**María Paula Alvarez**\n"
                    ."**Auxiliar de Crédito y Cobranza**\n"
                    ."Celular: +57 3153654788\n"
                    .'E-mail: cartera@derivadoscol.com',
            ],
        ],
        // The monthly circularization texts each company sends today, word
        // for word (client-facing: they keep "usted" as written).
        'circularizacion' => [
            'SGX' => [
                'asunto' => 'Circularización de Cartera – Corte al {fecha_cierre}',
                'inicio' => "Buen día\n\n"
                    ."Estimado cliente {cliente}\n\n"
                    ."Cordial saludo,\n\n"
                    .'Con el fin de mantener actualizada nuestra información contable y dar cumplimiento a nuestros procesos de conciliación, '
                    .'nos permitimos circularizar el estado de su cartera con corte al {fecha_cierre}.',
                'cierre' => 'Agradecemos validar el saldo registrado y, en caso de presentarse alguna diferencia, remitirnos sus observaciones y/o soportes '
                    .'correspondientes dentro de los próximos cinco (5) días hábiles, a los correos cartera@segurex.com, creditosgx@outlook.com y '
                    ."miguel.martinez@segurex.com.\n\n"
                    .'En caso de estar de acuerdo con la información suministrada, agradeceremos nos confirme su conformidad por este mismo medio. '
                    ."Su colaboración es fundamental para garantizar la correcta gestión y conciliación de nuestras cuentas.\n\n"
                    ."Quedamos atentos a cualquier inquietud\n\n"
                    ."Cordialmente,\n\n"
                    ."**María Paula Alvarez**\n"
                    .'Auxiliar de Cartera',
            ],
            'SDC' => [
                'asunto' => 'Circularización de Cartera – Corte al {fecha_cierre_del}',
                'inicio' => "Buen día\n\n"
                    ."Estimado cliente **{cliente}**\n\n"
                    ."Cordial saludo,\n\n"
                    .'Con el fin de mantener actualizada nuestra información contable y dar cumplimiento a nuestros procesos de conciliación, '
                    .'nos permitimos circularizar el estado de su cartera con **corte al {fecha_cierre}**.',
                'cierre' => 'Agradecemos validar el saldo registrado y, en caso de presentarse alguna diferencia, remitirnos sus observaciones y/o soportes '
                    .'correspondientes dentro de los próximos **cinco (5) días hábiles**, a los correos cartera@derivadoscol.com y '
                    ."miguel.martinez@derivadoscol.com.\n\n"
                    .'En caso de estar de acuerdo con la información suministrada, agradeceremos nos confirme su conformidad por este mismo medio. '
                    ."Su colaboración es fundamental para garantizar la correcta gestión y conciliación de nuestras cuentas.\n\n"
                    ."Quedamos atentos a cualquier inquietud.\n\n"
                    ."**Cordialmente,**\n\n"
                    ."**María Paula Alvarez**\n"
                    ."**Auxiliar de Crédito y Cobranza**\n"
                    ."**Celular: +57 3153654788**\n"
                    .'**E-mail: cartera@derivadoscol.com**',
            ],
        ],
    ],

];
