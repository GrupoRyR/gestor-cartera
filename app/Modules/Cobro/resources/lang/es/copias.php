<?php

// Carbon copies of the weekly collection and the monthly circularization
// mails, per company.
return [

    'titulo' => 'Copias del cobro semanal y la circularización',
    'descripcion' => 'Elige a quién se le envía copia de cada correo de cobro semanal y de la circularización mensual de la empresa. La prueba que te envías no lleva copias.',
    'empresas' => 'Empresas',

    'estado' => [
        'por_defecto' => 'Usa las copias por defecto',
        'editada' => 'Editadas por :nombre el :fecha',
    ],

    'campos' => [
        'fijos' => 'Siempre con copia a',
        'ayuda_correos' => 'Escribe un correo por línea o sepáralos con comas.',
        'asesor' => 'Copiar al asesor del cliente',
        'ayuda_asesor' => 'Se usa el correo del asesor registrado en SAP, cuando lo tiene.',
    ],

    'gerentes' => [
        'titulo' => 'Gerentes por grupo',
        'descripcion' => 'Cada gerente recibe copia de los correos de los clientes de su grupo. Escribe el grupo tal como aparece en el campo «Grupo» del cliente en SAP, por ejemplo DISTRIBUCION o CONS. RESIDENCIAL; no importan mayúsculas, tildes ni espacios.',
        'ayuda_vacias' => 'Deja una fila en blanco si no la necesitas.',
        'grupo' => 'Grupo de SAP (fila :numero)',
        'correos' => 'Correos del gerente (fila :numero)',
    ],

    'acciones' => [
        'guardar' => 'Guardar copias',
        'restaurar' => 'Volver a las copias por defecto',
    ],

    'mensajes' => [
        'guardadas' => 'Guardaste las copias de :empresa.',
        'restauradas' => 'Las copias de :empresa volvieron a las de por defecto.',
    ],

    'validacion' => [
        'invalidos' => 'Estos correos no son válidos: :correos.',
        'grupo_obligatorio' => 'Escribe el grupo de SAP de estos correos.',
        'correos_obligatorios' => 'Escribe al menos un correo para este grupo.',
        'fijos' => 'siempre con copia a',
        'grupo' => 'grupo',
        'correos' => 'correos',
    ],

];
