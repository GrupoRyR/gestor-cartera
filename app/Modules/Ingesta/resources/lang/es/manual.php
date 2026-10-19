<?php

// Manual upload of the cartera Excel (GET /ingesta/carga-manual and its
// preview), read with __('ingesta::manual.<key>').
return [

    'boton' => 'Carga manual',

    'titulo' => 'Carga manual de cartera',
    'descripcion' => 'Si el robot no publicó la cartera, sube el Excel «Cartera del día» de SAP. Antes de guardar verás una vista previa.',

    'formulario' => [
        'empresa' => 'Empresa',
        'empresa_elige' => 'Elige la empresa',
        'corte' => 'Fecha de corte',
        'corte_ayuda' => 'El día al que corresponde el saldo; no puede ser posterior a hoy.',
        'archivo' => 'Archivo de Excel',
        'archivo_ayuda' => 'Formato .xlsx, hasta 20 MB. Se lee la hoja «Datos» o, si no existe, la primera.',
        'enviar' => 'Ver vista previa',
        'cancelar' => 'Cancelar',
    ],

    'atributos' => [
        'empresa' => 'empresa',
        'fecha_corte' => 'fecha de corte',
        'archivo' => 'archivo',
    ],

    'validacion' => [
        'corte_futuro' => 'La fecha de corte no puede ser posterior a hoy.',
        'formato' => 'Sube el Excel de cartera en formato .xlsx.',
        'peso' => 'El archivo pesa más de 20 MB.',
    ],

    'errores' => [
        'ilegible' => 'No pudimos leer el archivo. Revisa que sea un Excel .xlsx sin contraseña.',
        'sin_hojas' => 'El archivo no tiene hojas.',
        'sin_encabezados' => 'No encontramos la fila de títulos: debe estar en las primeras :filas filas y tener las columnas :columnas.',
        'columnas_faltantes' => 'Al archivo le faltan estas columnas: :columnas.',
        'vencida' => 'La vista previa ya no está disponible. Sube el archivo de nuevo.',
    ],

    'vista_previa' => [
        'titulo' => 'Vista previa de la carga',
        'descripcion' => 'Revisa las cifras antes de confirmar. Nada se guarda hasta que toques «Confirmar carga».',
        'resumen' => 'Resumen',
        'filas_stat' => 'Filas',
        'clientes' => 'Clientes distintos',
        'total' => 'Saldo total',
        'vencido' => 'Saldo vencido',
        'corte' => 'Fecha de corte',
        'empresa' => 'Empresa',
        'sin_asesor_titulo' => 'Hay filas sin código de asesor',
        'sin_asesor' => '{1} 1 fila no tiene código de asesor: no encontramos a su asesor en la última carga de asesores ni al cliente en la cartera anterior. Ese cliente quedará sin asesor asignado.|[2,*] :count filas no tienen código de asesor: no encontramos a su asesor en la última carga de asesores ni al cliente en la cartera anterior. Esos clientes quedarán sin asesor asignado.',
        'ignoradas_titulo' => 'Columnas que no se cargan',
        'ignoradas' => 'Estas columnas del archivo no hacen parte de la cartera y se ignoran: :columnas.',
        'errores_titulo' => 'El archivo tiene errores',
        'errores' => 'Corrige el Excel y súbelo de nuevo; mientras tanto no se puede confirmar.',
        'errores_tabla' => 'Errores del archivo',
        'errores_columnas' => [
            'campo' => 'Dónde',
            'mensajes' => 'Qué pasó',
        ],
        'fila' => 'Fila :fila de Excel',
        'fila_columna' => 'Fila :fila de Excel, columna «:columna»',
        'muestra' => 'Primeras :count filas',
        'columnas' => [
            'cliente' => 'Cliente',
            'nit' => 'NIT',
            'documento' => 'Documento',
            'vencimiento' => 'Vencimiento',
            'dias' => 'Días',
            'saldo' => 'Saldo',
            'asesor' => 'Asesor',
        ],
        'sin_codigo' => 'Sin código',
        'confirmar' => 'Confirmar carga',
        'descartar' => 'Descartar',
    ],

    'resultado' => [
        'cargada' => 'Cargaste la cartera de :empresa con corte :fecha: :filas filas.',
        'repetida' => 'Esta carga ya estaba registrada.',
        'rechazada' => 'La carga se rechazó: :error',
        'descartada' => 'Descartaste la carga; no se guardó nada.',
    ],

    // Output of ingesta:limpiar-cargas-manuales.
    'limpieza' => [
        'borradas' => '{0} No había cargas manuales sin confirmar para borrar.|{1} Se borró 1 carga manual sin confirmar.|[2,*] Se borraron :count cargas manuales sin confirmar.',
    ],

];
