<?php

// The monthly budget screen (§7.3) and its template (Parte B).
return [
    'titulo' => 'Presupuesto',
    'descripcion' => 'El presupuesto de venta de cada código para el período. Cárgalo con la plantilla o edítalo fila por fila.',

    'periodo' => [
        'etiqueta' => 'Elegir el período',
        'campo' => 'Período',
        'ver' => 'Ver',
    ],

    'sin_parametros' => 'No hay parámetros vigentes en :periodo, así que no hay códigos a los que cargarles presupuesto.',

    'faltantes' => [
        'titulo' => 'Códigos sin presupuesto',
        'detalle' => 'Estos códigos tienen un mínimo de cumplimiento y aún no tienen presupuesto; al calcular, cada uno saldrá con la alerta «Sin presupuesto»:',
    ],

    'tabla' => [
        'titulo' => 'Presupuesto por código',
        'caption' => 'Presupuesto de cada código vigente en el período',
        'vacio' => 'No hay códigos vigentes en el período.',
        'columnas' => [
            'codigo' => 'Código',
            'nombre' => 'Nombre',
            'minimo' => 'Mínimo',
            'presupuesto' => 'Presupuesto',
            'canal' => 'Canal',
            'editado' => 'Última edición',
            'acciones' => 'Acciones',
        ],
        'campo_presupuesto' => 'Presupuesto del código :codigo',
        'campo_canal' => 'Canal del código :codigo',
        'sin_presupuesto' => 'Sin presupuesto',
        'editado_por' => ':fecha por :nombre',
        'guardar' => 'Guardar',
        'guardar_codigo' => 'Guardar el presupuesto del código :codigo',
        'eliminar' => 'Eliminar',
        'eliminar_codigo' => 'Eliminar el presupuesto del código :codigo',
    ],

    'carga' => [
        'titulo' => 'Cargar plantilla',
        'descripcion' => 'Sube un archivo .csv o .xlsx con las columnas «Período; Código de vendedor; Presupuesto; Canal». Revisamos todas las filas antes de guardar: si una tiene un error, no se guarda ninguna.',
        'archivo' => 'Archivo',
        'archivo_ayuda' => 'CSV separado por punto y coma o coma, o Excel (.xlsx). Los valores pueden ir como 77241857 o 77.241.857.',
        'enviar' => 'Cargar presupuesto',
        'plantilla' => 'Descargar plantilla',
        'vacio' => 'El archivo está vacío.',
        'sin_filas' => 'El archivo solo tiene encabezados: agrega una fila por código.',
        'encabezados' => 'La primera fila debe tener los encabezados :columnas.',
        'con_errores' => 'No guardamos nada: corrige las filas con error y vuelve a subir el archivo.',
        'mas_errores' => 'Y :cantidad filas más con error.',
        'listo' => 'Cargaste el presupuesto de :periodo: :creados nuevos, :actualizados actualizados y :sin_cambios sin cambios.',
        'errores' => [
            'fila' => 'Fila :fila: :errores',
            'vacia' => '(vacía)',
            'periodo_invalido' => 'el período «:valor» no es válido; escríbelo como AAAA-MM o MM/AAAA.',
            'otro_periodo' => 'el período :valor no es el que estás cargando (:periodo).',
            'codigo_invalido' => 'el código de vendedor «:valor» no es válido.',
            'codigo_desconocido' => 'el código :codigo no tiene parámetros vigentes en :periodo.',
            'codigo_repetido' => 'el código :codigo ya está en la fila :fila.',
            'valor_invalido' => 'el presupuesto «:valor» no es un valor válido.',
            'valor_no_positivo' => 'el presupuesto debe ser mayor que cero.',
            'canal_largo' => 'el canal no puede tener más de 100 caracteres.',
        ],
    ],

    'archivo' => [
        'formato' => 'El formato «.:extension» no es válido. Sube un archivo .csv o .xlsx.',
        'demasiadas_filas' => 'El archivo tiene demasiadas filas para ser un presupuesto.',
        'excel_danado' => 'No pude abrir el archivo de Excel. Revisa que no esté dañado ni protegido con contraseña.',
        'ilegible' => 'No pude leer el archivo.',
    ],

    'mensajes' => [
        'guardado' => 'Guardaste el presupuesto del código :codigo de :periodo.',
        'sin_cambios' => 'El presupuesto del código :codigo no cambió.',
        'eliminado' => 'Eliminaste el presupuesto del código :codigo de :periodo.',
        'no_existe' => 'El código :codigo no tenía presupuesto en :periodo.',
        'codigo_desconocido' => 'El código :codigo no tiene parámetros vigentes en :periodo.',
    ],

    'validacion' => [
        'valor' => 'presupuesto',
        'canal' => 'canal',
        'archivo' => 'archivo',
        'valor_invalido' => 'Escribe el presupuesto como 77241857 o 77.241.857.',
        'valor_no_positivo' => 'El presupuesto debe ser mayor que cero.',
    ],
];
