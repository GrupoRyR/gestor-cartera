<?php

// The «Parámetros» screen (§7.2) and the messages of the parameter versions
// (ParameterChangeRefused).
return [
    'motivo_requerido' => 'Escribe el motivo del cambio.',
    'tipo_desconocido' => 'El tipo de parámetro :tipo no existe.',
    'fecha_invalida' => 'La fecha :fecha no es válida; usa el formato AAAA-MM-DD.',
    'periodo_cerrado' => 'El período :periodo ya está cerrado: la versión debe empezar el :fecha o después.',
    'version_posterior' => 'Ya hay una versión de este parámetro que empieza el :fecha: agrega el cambio desde esa fecha o después.',
    'valores_invalidos' => 'Los valores de la versión no son válidos. Revisa los porcentajes, los días y los códigos.',

    'titulo' => 'Parámetros de comisiones',
    'contexto' => 'Comisiones',
    'descripcion' => 'Tasas, mínimos, tramos, equipos de directores, comisiones derivadas y opciones, cada uno con su vigencia. Es la única pantalla donde cambian las reglas: cada cambio es una versión nueva con su motivo.',

    'fecha' => [
        'etiqueta' => 'Elegir la fecha',
        'campo' => 'Vigentes al',
        'ver' => 'Ver',
        'viendo' => 'Valores vigentes al :fecha.',
    ],

    // Shown when a period is closed: its parameters are frozen.
    'cierre' => 'Las versiones nuevas deben empezar después del :fin, el fin del último período cerrado (:mes).',

    'pestanas' => [
        'etiqueta' => 'Tipos de parámetro',
        'codigos' => 'Códigos',
        'tramos' => 'Tramos de recaudo',
        'equipos' => 'Equipos de directores',
        'derivadas' => 'Comisiones derivadas',
        'opciones' => 'Opciones',
    ],

    // «del código 14», «de la tabla de tramos»… (:clave is the code, the channel or the key).
    'de' => [
        'codigo' => 'del código :clave',
        'tramos' => 'de la tabla de tramos',
        'equipo' => 'del equipo :clave',
        'derivada' => 'de la comisión derivada :clave',
        'opciones' => 'de las opciones de liquidación',
    ],

    // Field names, shared by the tables, the history and the forms.
    'campos' => [
        'codigo' => 'Código',
        'nombre' => 'Nombre',
        'persona' => 'Persona',
        'tasa_venta' => 'Tasa de venta',
        'tasa_recaudo' => 'Tasa de recaudo',
        'cumplimiento_minimo' => 'Mínimo de cumplimiento',
        'alcance' => 'Consolidado',
        'usa_tramos' => 'Tramos',
        'desde' => 'Desde días',
        'hasta' => 'Hasta días',
        'participacion' => '% de la tasa',
        'clave' => 'Clave',
        'canal' => 'Canal',
        'director' => 'Director',
        'codigo_asociado' => 'Código asociado',
        'tasa' => 'Tasa',
        'miembros' => 'Códigos del equipo',
        'etiqueta' => 'Etiqueta',
        'codigo_origen' => 'Código origen',
        'base' => 'Base',
        'vigencia' => 'Vigencia',
        'acciones' => 'Acciones',
    ],

    'valores' => [
        'si' => 'Sí',
        'no' => 'No',
        'con_tramos' => 'Con tramos',
        'sin_tramos' => 'Sin tramos',
        'sin_limite' => 'Sin límite',
        'sin_persona' => 'El mismo código',
        'ninguno' => 'Ninguno',
        'tramo' => ':desde a :hasta días: :participacion',
        'tramo_abierto' => ':desde días o más: :participacion',
        'desde' => 'Desde :fecha',
        'cambia' => 'Cambia el :fecha',
        'empieza' => 'Empieza el :fecha',
    ],

    'lista' => [
        'historial' => 'Historial',
        'historial_de' => 'Historial :de',
        'nueva_version' => 'Nueva versión',
        'nueva_version_de' => 'Nueva versión :de',
        'vacio' => 'No hay versiones vigentes al :fecha.',
    ],

    'codigos' => [
        'titulo' => 'Códigos de vendedor',
        'descripcion' => 'Tasa de venta, tasa de recaudo y mínimo de cumplimiento de cada código de SAP. Los códigos de una misma persona se suman en nómina.',
        'caption' => 'Parámetros de los códigos de vendedor',
        'nuevo' => 'Nuevo código',
    ],

    'tramos' => [
        'titulo' => 'Tramos de recaudo',
        'descripcion' => 'Qué parte de la tasa de recaudo se paga según los días entre la factura y el pago.',
        'caption' => 'Tramos de recaudo vigentes',
        'nuevo' => 'Nueva versión de los tramos',
        'vigente' => 'Vigente desde el :fecha.',
        'cambia' => 'Cambia el :fecha.',
        'empieza' => 'Empieza el :fecha.',
    ],

    'equipos' => [
        'titulo' => 'Equipos de directores',
        'descripcion' => 'El director de canal gana su tasa sobre la venta más el recaudo de los códigos de su equipo.',
        'caption' => 'Equipos de directores vigentes',
        'nuevo' => 'Nuevo equipo',
    ],

    'derivadas' => [
        'titulo' => 'Comisiones derivadas',
        'descripcion' => 'Comisiones que se calculan sobre la venta o el recaudo de otro código, como el 81.1 y el 85.1. Cada una es una fila propia del consolidado.',
        'caption' => 'Comisiones derivadas vigentes',
        'nuevo' => 'Nueva comisión derivada',
    ],

    'opciones' => [
        'titulo' => 'Opciones de liquidación',
        'descripcion' => 'Cómo responde la liquidación a los puntos por confirmar 14.2 a 14.7. Los valores por defecto reproducen el Excel.',
        'caption' => 'Opciones de liquidación vigentes',
        'nuevo' => 'Nueva versión de las opciones',
        'por_defecto' => 'Aún no hay una versión guardada de las opciones: se usan los valores por defecto, que reproducen el Excel.',
        'columnas' => [
            'punto' => 'Punto',
            'opcion' => 'Opción',
            'valor' => 'Valor',
            'detalle' => 'Qué significa',
        ],
        'campos' => [
            'base_venta' => [
                'etiqueta' => 'Base de la comisión por venta',
                'ayuda' => 'Con «Valor reportado por SAP» el total de SAP es la base, como en el Excel. Con «Valor con IVA» la base es el total dividido entre 1 más el IVA.',
            ],
            'iva_venta' => [
                'etiqueta' => 'IVA que se descuenta de la venta',
                'ayuda' => 'Solo cuenta con «Valor con IVA (se descuenta)». Si lo dejas vacío, es 19 %.',
            ],
            'notas_credito' => [
                'etiqueta' => 'Notas crédito y devoluciones',
                'ayuda' => 'Netear: restan de la venta del período en que se registran, como en el Excel. Excluir: no se tienen en cuenta.',
            ],
            'cruces_en_derivadas' => [
                'etiqueta' => 'Sumar los cruces en la base del 85.1',
                'ayuda' => 'El Excel no suma los cruces del código 85 en el 85.1. Márcalo para sumarlos.',
            ],
            'cruces_en_directores' => [
                'etiqueta' => 'Sumar los cruces en la base de los directores',
                'ayuda' => 'Por defecto los directores ganan solo sobre los recaudos de SAP de su equipo (supuesto por confirmar). Márcalo para sumar también los cruces.',
            ],
            'ubicacion_director' => [
                'etiqueta' => 'Comisión de director de los códigos 50 y 53',
                'ayuda' => 'Bloque aparte: se paga como un rol propio «Director» del canal, como en el Excel. En el código asociado: se suma al total del código 50 o 53.',
            ],
            'vendedor_cruce' => [
                'etiqueta' => 'Vendedor de un cruce',
                'ayuda' => 'Qué vendedor gana el cruce cuando el cliente cambió de asesor entre la factura y el cruce.',
            ],
            'alcance_cumplimiento' => [
                'etiqueta' => 'Cumplimiento del presupuesto',
                'ayuda' => 'Por código, como hoy. Por persona: los códigos de una persona comparten un cumplimiento, la suma de sus ventas sobre la suma de sus presupuestos.',
            ],
            'moneda' => [
                'etiqueta' => 'Ventas y recaudos en moneda extranjera',
                'ayuda' => 'Valor reportado: se deja el valor y se avisa, como en el Excel. Convertir con la TRM: se multiplica por la TRM, solo si la moneda no es COP.',
            ],
        ],
    ],

    // The forms that add a version (ParameterFormController).
    'formulario' => [
        'nuevo' => [
            'codigo' => 'Nuevo código',
            'equipo' => 'Nuevo equipo',
            'derivada' => 'Nueva comisión derivada',
        ],
        'descripcion' => 'Se guarda como una versión nueva desde la fecha que elijas: la versión anterior termina el día antes. Las liquidaciones cerradas no cambian.',
        'descripcion_nuevo' => 'Escribe los valores de la primera versión y la fecha desde la que rige.',
        'partida' => 'Los campos traen la versión vigente desde el :fecha.',
        'revisa' => 'Revisa los campos marcados.',
        'no_guardada' => 'No se guardó la versión',
        'valores' => 'Valores',
        'vigencia' => 'Vigencia y motivo',
        'vigente_desde' => 'Vigente desde',
        'vigente_desde_ayuda' => 'Si empieza a mitad de mes, rige para la liquidación de todo ese mes.',
        'motivo' => 'Motivo del cambio',
        'motivo_ayuda' => 'Queda en la bitácora junto con los valores anterior y nuevo.',
        'guardar' => 'Guardar versión',
        'cancelar' => 'Cancelar',
        'guardada' => 'Guardaste la versión :de que rige desde el :fecha.',

        'codigo' => [
            'clave_ayuda' => 'El código del vendedor en SAP, por ejemplo 14.',
            'nombre_ayuda' => 'Así sale en los informes, por ejemplo «Mónica Rivera» u «Oficina – Varios».',
            'persona_nombre' => 'Persona que cobra',
            'persona_nombre_ayuda' => 'Opcional. Los códigos de una misma persona se suman en el valor a pagar por nómina. Sin persona, el código cobra a su nombre.',
            'persona_id' => 'Clave de la persona',
            'persona_id_ayuda' => 'Debe ser la misma en todos los códigos de la persona, por ejemplo harold-marin. Si la dejas vacía, se arma con el nombre.',
            'tasa_ayuda' => 'Porcentaje, por ejemplo 0,75.',
            'cumplimiento_ayuda' => 'Venta sobre presupuesto que se necesita para ganar la comisión por venta. Cero paga desde el primer peso.',
            'alcance_ayuda' => 'Regular: sección 1 del consolidado. Aparte: directores que se pagan aparte (81, 84, 90). Control: no comisiona, solo cuadra las ventas con SAP (80, 100).',
            'usa_tramos' => 'Usa los tramos de días en el recaudo',
            'usa_tramos_ayuda' => 'Sin tramos se paga la tasa de recaudo completa, sin importar los días (código 85).',
        ],

        'tramos' => [
            'ayuda' => 'El primer tramo empieza en 0 días, cada tramo empieza el día siguiente al fin del anterior y solo el último queda sin límite (deja «Hasta días» vacío). Las filas vacías no se guardan.',
            'caption' => 'Tramos de la nueva versión',
            'fila' => ':campo, fila :fila',
        ],

        'equipo' => [
            'clave_ayuda' => 'En minúsculas y sin espacios, por ejemplo construccion-residencial. No se puede cambiar después.',
            'canal_ayuda' => 'Así sale en los informes, por ejemplo «Distribución».',
            'director_nombre' => 'Director',
            'director_id' => 'Clave del director',
            'director_id_ayuda' => 'La misma clave de persona de sus códigos, por ejemplo jessika-quintero. Si la dejas vacía, se arma con el nombre.',
            'codigo_asociado_ayuda' => 'Opcional. El código propio del director (53, 50); se usa si la comisión de director va en el código asociado (punto 14.4).',
            'tasa_ayuda' => 'Se aplica a la venta más el recaudo del equipo, por ejemplo 0,2.',
            'miembros_ayuda' => 'Separados por comas, en el orden del informe, por ejemplo 14, 15, 16.',
        ],

        'derivada' => [
            'clave_ayuda' => 'Por ejemplo 81.1. No puede ser solo un número y no se puede cambiar después.',
            'etiqueta_ayuda' => 'El nombre de la fila en el consolidado, por ejemplo «Sergio Caballero (venta)».',
            'persona_nombre' => 'Persona que cobra',
            'persona_id' => 'Clave de la persona',
            'persona_id_ayuda' => 'Si la dejas vacía, se arma con el nombre.',
            'codigo_origen_ayuda' => 'El código sobre cuya venta o recaudo se calcula.',
            'base_ayuda' => 'Venta: sobre la venta del código origen. Recaudo: sobre su recaudo sin IVA, con tasa plana (sin tramos).',
        ],

        // Names of the fields in the validation messages.
        'atributos' => [
            'clave' => 'clave',
            'nombre' => 'nombre',
            'persona_nombre' => 'persona que cobra',
            'persona_id' => 'clave de la persona',
            'tasa_venta' => 'tasa de venta',
            'tasa_recaudo' => 'tasa de recaudo',
            'cumplimiento_minimo' => 'mínimo de cumplimiento',
            'alcance' => 'consolidado',
            'usa_tramos' => 'tramos',
            'tramos' => 'tramos',
            'tramos.*.desde' => 'desde días',
            'tramos.*.hasta' => 'hasta días',
            'tramos.*.participacion' => '% de la tasa',
            'canal' => 'canal',
            'director_nombre' => 'director',
            'director_id' => 'clave del director',
            'codigo_asociado' => 'código asociado',
            'tasa' => 'tasa',
            'miembros' => 'códigos del equipo',
            'etiqueta' => 'etiqueta',
            'codigo_origen' => 'código origen',
            'base' => 'base',
            'base_venta' => 'base de la comisión por venta',
            'iva_venta' => 'IVA que se descuenta de la venta',
            'notas_credito' => 'notas crédito y devoluciones',
            'cruces_en_derivadas' => 'cruces en la base del 85.1',
            'cruces_en_directores' => 'cruces en la base de los directores',
            'ubicacion_director' => 'comisión de director',
            'vendedor_cruce' => 'vendedor de un cruce',
            'alcance_cumplimiento' => 'cumplimiento del presupuesto',
            'moneda' => 'moneda extranjera',
            'vigente_desde' => 'vigente desde',
            'motivo' => 'motivo',
        ],
    ],

    'validacion' => [
        // By field (the last segment of its name).
        'porcentaje' => [
            'tasa_venta' => 'La tasa de venta debe ser un porcentaje entre 0 y 100, por ejemplo 0,75.',
            'tasa_recaudo' => 'La tasa de recaudo debe ser un porcentaje entre 0 y 100, por ejemplo 0,75.',
            'cumplimiento_minimo' => 'El mínimo de cumplimiento debe ser un porcentaje entre 0 y 100, por ejemplo 90.',
            'tasa' => 'La tasa debe ser un porcentaje entre 0 y 100, por ejemplo 0,2.',
            'participacion' => 'El % de la tasa debe ser un porcentaje entre 0 y 100, por ejemplo 90.',
            'iva_venta' => 'El IVA debe ser un porcentaje entre 0 y 100, por ejemplo 19.',
        ],
        'codigos' => 'Escribe los códigos del equipo separados por comas, por ejemplo 14, 15, 16.',
        'codigo_repetido' => 'El código :codigo está repetido en el equipo.',
        'persona_id' => 'La clave de la persona va en minúsculas, sin espacios ni tildes, por ejemplo harold-marin.',
        'clave_equipo' => 'La clave va en minúsculas, sin espacios ni tildes, por ejemplo construccion-residencial.',
        'clave_derivada' => 'La clave lleva números o letras en minúscula separados por puntos, por ejemplo 81.1.',
        'clave_numero' => 'La clave no puede ser solo un número: se confundiría con un código de vendedor.',
        'clave_reservada' => 'Esa clave está reservada; elige otra.',
        'sin_tramos' => 'Escribe al menos un tramo.',
    ],

    'historial' => [
        'descripcion' => 'Cada versión con su vigencia, sus valores, el motivo y quién la registró. Una versión nueva termina la anterior el día antes de empezar.',
        'caption' => 'Versiones del parámetro, de la más reciente a la más antigua',
        'volver' => 'Volver a los parámetros',
        'vacio' => 'Este parámetro aún no tiene versiones.',
        'abierta' => 'Abierta',
        'vigente' => 'Vigente',
        'proxima' => 'Próxima',
        'anterior' => 'Anterior',
        'columnas' => [
            'estado' => 'Estado',
            'desde' => 'Vigente desde',
            'hasta' => 'Vigente hasta',
            'valores' => 'Valores',
            'motivo' => 'Motivo',
            'autor' => 'Registró',
            'registro' => 'Registrada',
        ],
    ],
];
